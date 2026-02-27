<?php

namespace RishumitPlugin\Storage;

class AzureBlobStorage
{
    private string $accountName;
    private string $accountKey;
    private string $container;
    private string $endpointSuffix;

    public function __construct()
    {
        $connectionString = defined('AZURE_BLOB_CONNECTION_STRING') ? AZURE_BLOB_CONNECTION_STRING : '';

        if (empty($connectionString)) {
            throw new \RuntimeException('AZURE_BLOB_CONNECTION_STRING is not defined in wp-config.php');
        }

        $parts = $this->parseConnectionString($connectionString);

        $this->accountName = $parts['AccountName'] ?? '';
        $this->accountKey = $parts['AccountKey'] ?? '';
        $this->endpointSuffix = $parts['EndpointSuffix'] ?? 'core.windows.net';
        $this->container = defined('AZURE_BLOB_CONTAINER') ? AZURE_BLOB_CONTAINER : 'form-uploads';

        if (empty($this->accountName) || empty($this->accountKey)) {
            throw new \RuntimeException('Azure Blob connection string is missing AccountName or AccountKey');
        }
    }

    /**
     * Check if Azure Blob Storage is configured.
     */
    public static function isConfigured(): bool
    {
        return defined('AZURE_BLOB_CONNECTION_STRING') && !empty(AZURE_BLOB_CONNECTION_STRING);
    }

    /**
     * Upload a local file to Azure Blob Storage.
     *
     * @param string $localFilePath Absolute path to the local file.
     * @return string|null The public Azure Blob URL on success, null on failure.
     */
    public function upload(string $localFilePath, string $userId = 'unknown'): ?string
    {
        if (!file_exists($localFilePath) || !is_readable($localFilePath)) {
            error_log("☁️ Azure Blob: File not found or not readable: $localFilePath");
            return null;
        }

        $fileContents = file_get_contents($localFilePath);
        if ($fileContents === false) {
            error_log("☁️ Azure Blob: Failed to read file: $localFilePath");
            return null;
        }

        $originalFilename = basename($localFilePath);
        $contentType = $this->getContentType($originalFilename);
        $blobName = $this->generateBlobName($originalFilename, $userId);

        $url = "https://{$this->accountName}.blob.{$this->endpointSuffix}/{$this->container}/{$blobName}";

        $date = gmdate('D, d M Y H:i:s T');
        $contentLength = strlen($fileContents);

        $headers = [
            'x-ms-blob-type' => 'BlockBlob',
            'x-ms-date' => $date,
            'x-ms-version' => '2020-10-02',
            'Content-Type' => $contentType,
            'Content-Length' => $contentLength,
        ];

        $authHeader = $this->buildAuthorizationHeader(
            'PUT',
            $contentLength,
            $contentType,
            $date,
            $blobName
        );
        $headers['Authorization'] = $authHeader;

        $response = wp_remote_request($url, [
            'method' => 'PUT',
            'headers' => $headers,
            'body' => $fileContents,
            'timeout' => 60,
        ]);

        if (is_wp_error($response)) {
            error_log("☁️ Azure Blob: Upload failed for {$originalFilename}: " . $response->get_error_message());
            return null;
        }

        $statusCode = wp_remote_retrieve_response_code($response);

        if ($statusCode !== 201) {
            $body = wp_remote_retrieve_body($response);
            error_log("☁️ Azure Blob: Upload returned HTTP {$statusCode} for {$originalFilename}");
            error_log("☁️ Azure Blob: Response body: {$body}");
            return null;
        }

        // Generate a SAS URL (account has public access disabled)
        $sasUrl = $this->generateSasUrl($blobName);

        error_log("☁️ Azure Blob: Successfully uploaded {$originalFilename} → {$sasUrl}");
        return $sasUrl;
    }

    /**
     * Build the SharedKey authorization header for Azure Blob Storage REST API.
     */
    private function buildAuthorizationHeader(
        string $method,
        int $contentLength,
        string $contentType,
        string $date,
        string $blobName
    ): string {
        // Canonicalized headers (must be sorted alphabetically)
        $canonicalizedHeaders = "x-ms-blob-type:BlockBlob\n"
            . "x-ms-date:{$date}\n"
            . "x-ms-version:2020-10-02";

        // Canonicalized resource
        $canonicalizedResource = "/{$this->accountName}/{$this->container}/{$blobName}";

        // String to sign (https://learn.microsoft.com/en-us/rest/api/storageservices/authorize-with-shared-key)
        $stringToSign = implode("\n", [
            $method,                // HTTP method
            '',                     // Content-Encoding
            '',                     // Content-Language
            $contentLength,         // Content-Length
            '',                     // Content-MD5
            $contentType,           // Content-Type
            '',                     // Date (empty when using x-ms-date)
            '',                     // If-Modified-Since
            '',                     // If-Match
            '',                     // If-None-Match
            '',                     // If-Unmodified-Since
            '',                     // Range
            $canonicalizedHeaders,
            $canonicalizedResource,
        ]);

        $decodedKey = base64_decode($this->accountKey);
        $signature = base64_encode(hash_hmac('sha256', $stringToSign, $decodedKey, true));

        return "SharedKey {$this->accountName}:{$signature}";
    }

    /**
     * Generate a SAS (Shared Access Signature) URL for a blob.
     * Grants read-only access for 10 years.
     */
    private function generateSasUrl(string $blobName): string
    {
        $start = gmdate('Y-m-d\TH:i:s\Z', time() - 300); // 5 min in the past (clock skew)
        $expiry = gmdate('Y-m-d\TH:i:s\Z', strtotime('+10 years'));

        // SAS parameters
        $signedPermissions = 'r';          // read only
        $signedService = 'b';              // blob
        $signedResourceType = 'b';         // blob (individual)
        $signedProtocol = 'https';
        $signedVersion = '2020-10-02';

        $canonicalizedResource = "/blob/{$this->accountName}/{$this->container}/{$blobName}";

        // String to sign for Service SAS
        // https://learn.microsoft.com/en-us/rest/api/storageservices/create-service-sas
        // For version 2020-10-02, signedEncryptionScope is NOT included
        $stringToSign = implode("\n", [
            $signedPermissions,     // sp
            $start,                 // st
            $expiry,                // se
            $canonicalizedResource, // canonicalized resource
            '',                     // signed identifier
            '',                     // signed IP
            $signedProtocol,        // signed protocol
            $signedVersion,         // signed version
            $signedResourceType,    // signed resource (b = blob)
            '',                     // signed snapshot time
            '',                     // rscc (Cache-Control)
            '',                     // rscd (Content-Disposition)
            '',                     // rsce (Content-Encoding)
            '',                     // rscl (Content-Language)
            '',                     // rsct (Content-Type)
        ]);

        $decodedKey = base64_decode($this->accountKey);
        $signature = base64_encode(hash_hmac('sha256', $stringToSign, $decodedKey, true));

        $sasToken = http_build_query([
            'sv' => $signedVersion,
            'st' => $start,
            'se' => $expiry,
            'sr' => $signedResourceType,
            'sp' => $signedPermissions,
            'spr' => $signedProtocol,
            'sig' => $signature,
        ]);

        $baseUrl = "https://{$this->accountName}.blob.{$this->endpointSuffix}/{$this->container}/{$blobName}";

        return $baseUrl . '?' . $sasToken;
    }

    /**
     * Generate a unique blob name with date-based folder structure.
     */
    private function generateBlobName(string $originalFilename, string $userId = 'unknown'): string
    {
        $year = date('Y');
        $month = date('m');
        $uniqueId = uniqid('', true);
        // Sanitize filename and userId: keep only alphanumeric, dots, hyphens, underscores
        $safeFilename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $originalFilename);
        $safeUserId = preg_replace('/[^a-zA-Z0-9._-]/', '_', $userId);

        return "forms/{$year}/{$month}/{$safeUserId}/{$uniqueId}_{$safeFilename}";
    }

    /**
     * Determine content type from file extension.
     */
    private function getContentType(string $filename): string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        $types = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'heic' => 'image/heic',
            'heif' => 'image/heif',
            'tiff' => 'image/tiff',
            'tif' => 'image/tiff',
            'bmp' => 'image/bmp',
        ];

        return $types[$ext] ?? 'application/octet-stream';
    }

    /**
     * Parse Azure Storage connection string into key-value pairs.
     */
    private function parseConnectionString(string $connectionString): array
    {
        $parts = [];
        foreach (explode(';', $connectionString) as $segment) {
            $segment = trim($segment);
            if (empty($segment)) continue;
            $eqPos = strpos($segment, '=');
            if ($eqPos !== false) {
                $key = substr($segment, 0, $eqPos);
                $value = substr($segment, $eqPos + 1);
                $parts[$key] = $value;
            }
        }
        return $parts;
    }
}
