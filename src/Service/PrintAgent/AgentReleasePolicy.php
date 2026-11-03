<?php

declare(strict_types=1);

namespace VenPosTPV\Service\PrintAgent;

/**
 * Versions of the print agent (PRD U1, U4 phase 1): the minimum version the API
 * accepts (426 below it) and the latest version with its download link, both from
 * configuration (TPV_AGENT_MIN_VERSION, TPV_AGENT_LATEST_VERSION,
 * TPV_AGENT_DOWNLOAD_URL). Empty means "none". Phase 1 publishes no signed
 * manifest; the agent only learns that a new version exists.
 */
class AgentReleasePolicy
{
    public function __construct(
        private readonly string $minVersion = '',
        private readonly string $latestVersion = '',
        private readonly string $downloadUrl = '',
    ) {
    }

    public function isBelowMinimum(?string $version): bool
    {
        $minimum = trim($this->minVersion);
        if ($minimum === '') {
            return false;
        }
        if ($version === null || !self::isVersion($version)) {
            return true;
        }

        return version_compare($version, $minimum, '<');
    }

    public function isUpdateAvailable(?string $version): bool
    {
        $latest = trim($this->latestVersion);
        if ($latest === '' || $version === null || !self::isVersion($version)) {
            return false;
        }

        return version_compare($version, $latest, '<');
    }

    public function latestVersion(): ?string
    {
        $latest = trim($this->latestVersion);

        return $latest === '' ? null : $latest;
    }

    public function downloadUrl(string $os = '', string $arch = ''): ?string
    {
        $url = trim($this->downloadUrl);
        if ($url === '') {
            return null;
        }

        return strtr($url, ['{os}' => rawurlencode($os), '{arch}' => rawurlencode($arch), '{version}' => rawurlencode((string) $this->latestVersion())]);
    }

    public static function isVersion(string $version): bool
    {
        return preg_match('/\A\d{1,4}\.\d{1,4}\.\d{1,4}(?:[-+][0-9A-Za-z.-]{1,20})?\z/', $version) === 1;
    }
}
