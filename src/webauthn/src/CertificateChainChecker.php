<?php

declare(strict_types=1);

/*
 * The MIT License (MIT)
 *
 * Copyright (c) 2014-2020 Spomky-Labs
 *
 * This software may be modified and distributed under the terms
 * of the MIT license.  See the LICENSE file for details.
 */

namespace Webauthn;

use Assert\Assertion;
use function count;
use InvalidArgumentException;
use function is_int;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use RuntimeException;
use Safe\Exceptions\FilesystemException;
use function Safe\file_put_contents;
use function Safe\mkdir;
use function Safe\rename;
use function Safe\sprintf;
use function Safe\tempnam;
use function Safe\unlink;
use Symfony\Component\Process\Process;

class CertificateChainChecker
{
    /**
     * @var ClientInterface
     */
    private $client;

    /**
     * @var RequestFactoryInterface
     */
    private $requestFactory;

    public function __construct(ClientInterface $client, RequestFactoryInterface $requestFactory)
    {
        $this->client = $client;
        $this->requestFactory = $requestFactory;
    }

    /**
     * @param string[] $authenticatorCertificates
     * @param string[] $trustedCertificates
     */
    public function check(array $authenticatorCertificates, array $trustedCertificates): void
    {
        if (0 === count($trustedCertificates)) {
            $this->checkCertificatesValidity($authenticatorCertificates);

            return;
        }

        $hasCrls = false;
        $processArguments = ['-no-CAfile', '-no-CApath'];

        $caDirname = $this->createTemporaryDirectory();
        $processArguments[] = '--CApath';
        $processArguments[] = $caDirname;

        foreach ($trustedCertificates as $certificate) {
            $this->prepareCertificate($caDirname, $certificate, 'webauthn-trusted-', '.pem', $hasCrls);
        }

        $rehashProcess = new Process(['openssl', 'rehash', $caDirname]);
        $rehashProcess->run();
        while ($rehashProcess->isRunning()) {
            //Just wait
        }
        if (!$rehashProcess->isSuccessful()) {
            throw new InvalidArgumentException('Invalid certificate or certificate chain');
        }

        $filenames = [];
        $leafCertificate = array_shift($authenticatorCertificates);
        $leafFilename = $this->prepareCertificate(sys_get_temp_dir(), $leafCertificate, 'webauthn-leaf-', '.pem', $hasCrls);
        $filenames[] = $leafFilename;

        foreach ($authenticatorCertificates as $certificate) {
            $untrustedFilename = $this->prepareCertificate(sys_get_temp_dir(), $certificate, 'webauthn-untrusted-', '.pem', $hasCrls);
            $processArguments[] = '-untrusted';
            $processArguments[] = $untrustedFilename;
            $filenames[] = $untrustedFilename;
        }

        $processArguments[] = $leafFilename;
        if ($hasCrls) {
            array_unshift($processArguments, '-crl_check');
        }
        array_unshift($processArguments, 'openssl', 'verify');

        $process = new Process($processArguments);
        $process->run();
        while ($process->isRunning()) {
            //Just wait
        }

        foreach ($filenames as $filename) {
            try {
                unlink($filename);
            } catch (FilesystemException $e) {
                continue;
            }
        }
        $this->deleteDirectory($caDirname);

        if (!$process->isSuccessful()) {
            throw new InvalidArgumentException('Invalid certificate or certificate chain');
        }
    }

    public function fixPEMStructure(string $certificate): string
    {
        $pemCert = '-----BEGIN CERTIFICATE-----'.PHP_EOL;
        $pemCert .= chunk_split($certificate, 64, PHP_EOL);
        $pemCert .= '-----END CERTIFICATE-----'.PHP_EOL;

        return $pemCert;
    }

    /**
     * @param string[] $certificates
     */
    private function checkCertificatesValidity(array $certificates): void
    {
        foreach ($certificates as $certificate) {
            $parsed = openssl_x509_parse($certificate);
            Assertion::isArray($parsed, 'Unable to read the certificate');
            Assertion::keyExists($parsed, 'validTo_time_t', 'The certificate has no validity period');
            Assertion::keyExists($parsed, 'validFrom_time_t', 'The certificate has no validity period');
            Assertion::lessOrEqualThan(time(), $parsed['validTo_time_t'], 'The certificate expired');
            Assertion::greaterOrEqualThan(time(), $parsed['validFrom_time_t'], 'The certificate is not usable yet');
        }
    }

    private function createTemporaryDirectory(): string
    {
        $caDir = tempnam(sys_get_temp_dir(), 'webauthn-ca-');
        if (file_exists($caDir)) {
            unlink($caDir);
        }
        mkdir($caDir);
        if (!is_dir($caDir)) {
            throw new RuntimeException(sprintf('Directory "%s" was not created', $caDir));
        }

        return $caDir;
    }

    private function deleteDirectory(string $dirname): void
    {
        $rehashProcess = new Process(['rm', '-rf', $dirname]);
        $rehashProcess->run();
        while ($rehashProcess->isRunning()) {
            //Just wait
        }
    }

    private function prepareCertificate(string $folder, string $certificate, string $prefix, string $suffix, bool &$hasCrls): string
    {
        $untrustedFilename = tempnam($folder, $prefix);
        rename($untrustedFilename, $untrustedFilename.$suffix);
        file_put_contents($untrustedFilename.$suffix, $certificate, FILE_APPEND);

        $crl = $this->getCrls($certificate);
        if ('' !== $crl) {
            $hasCrls = true;
            file_put_contents($untrustedFilename.$suffix, PHP_EOL, FILE_APPEND);
            file_put_contents($untrustedFilename.$suffix, $crl, FILE_APPEND);
        }

        return $untrustedFilename.$suffix;
    }

    private function getCrls(string $certificate): string
    {
        $parsed = openssl_x509_parse($certificate);
        if ($parsed === false || !isset($parsed['extensions']['crlDistributionPoints'])) {
            return '';
        }
        $endpoint = $parsed['extensions']['crlDistributionPoints'];
        $pos = mb_strpos($endpoint, 'URI:');
        if (!is_int($pos)) {
            return '';
        }

        $endpoint = trim(mb_substr($endpoint, $pos + 4));
        $request = $this->requestFactory->createRequest('GET', $endpoint);
        $response = $this->client->sendRequest($request);

        if (200 !== $response->getStatusCode()) {
            return '';
        }

        $content = $response->getBody()->getContents();

        return CertificateToolbox::convertDERToPEM($content, 'X509 CRL');
    }
}
