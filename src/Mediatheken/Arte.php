<?php

namespace TheiNaD\DSMediatheken\Mediatheken;

use TheiNaD\DSMediatheken\Utils\Mediathek;
use TheiNaD\DSMediatheken\Utils\Result;

/**
 * @author    Daniel Gehn <me@theinad.com>
 * @copyright 2018-2020 Daniel Gehn
 * @license   http://opensource.org/licenses/MIT Licensed under MIT License
 */
class Arte extends Mediathek
{
    protected static $LANGUAGE_MAP = [
        'de' => 'de',
        'fr' => 'fr',
    ];
    protected static $LANGUAGE_MAP_SHORT_LIBELLE = [
        'de' => 'de',
        'fr' => ['vf', 'vof'],
    ];
    protected static $OV_SHORT_LIBELLE = [
        'og',
        'ov',
        'omu',
        'vostf',
        'vo',
    ];

    protected static $SUPPORT_MATCHER = 'arte.tv';

    protected $language = 'de';
    protected $languageShortLibelle = 'de';

    protected $platform;
    protected $detectedLanguage;
    protected $subdomain;

    /**
     * @param string $url
     * @param string $username
     * @param string $password
     *
     * @return Result|null
     */
    public function getDownloadInfo($url, $username = '', $password = '')
    {
        $this->getLogger()->log('determining website and language by url ' . $url);
        if ($this->extractLanguageAndPlatformFromUrl($url) === false) {
            return null;
        }

        $this->changeLanguageIfDetected();

        $this->getLogger()->log(
            sprintf(
                'using language %s (%s)',
                $this->language,
                print_r($this->languageShortLibelle, true)
            )
        );

        $this->getLogger()->log('fetching page content.');

        $videoPage = $this->getTools()->curlRequest($url);
        if ($videoPage === null) {
            return null;
        }

        $json = $this->processVideoPage($videoPage);
        if ($json === null) {
            return null;
        }

        $result = $this->getBestSource($json);
        if (!$result->hasUri()) {
            $result = $this->getBestSource($json, true);
        }

        if (!$result->hasUri()) {
            return null;
        }

        $result = $this->addTitle($result, $json);
        $result->setUri($this->getTools()->addProtocolFromUrlIfMissing($result->getUri(), $url));

        return $result;
    }

    /**
     * @param string $url
     *
     * @return bool
     */
    protected function extractLanguageAndPlatformFromUrl($url)
    {
        if (preg_match('#https?://(\w+\.)?arte.tv/(?:guide/)?([a-zA-Z]+)#si', $url, $match) > 0) {
            $this->platform = 'arte';
            $this->subdomain = $match[1];
            $this->detectedLanguage = isset($match[2]) ? $match[2] : null;

            return true;
        }

        $this->getLogger()->log('not a known arte website');

        return false;
    }

    /**
     * @return void
     */
    protected function changeLanguageIfDetected()
    {
        if ($this->languageExists($this->detectedLanguage)) {
            $this->language = self::$LANGUAGE_MAP[$this->detectedLanguage];
            $this->languageShortLibelle = self::$LANGUAGE_MAP_SHORT_LIBELLE[$this->detectedLanguage];
        }
    }

    /**
     * @param string $videoPage
     *
     * @return object|null
     */
    protected function processVideoPage($videoPage)
    {
        if (preg_match('#src=["|\']http.*?json_url=(.*?)%3F.*["|\']#si', $videoPage, $match) === 1) {
            $playerUrl = urldecode($match[1]);
            $this->getLogger()->log('the player is located at ' . $playerUrl);
            $json = $this->getTools()->curlRequest($playerUrl);
            if ($json === null) {
                return null;
            }

            return json_decode($json, false);
        }

        $this->getLogger()->log('could not identify player meta.');

        return null;
    }

    /**
     * @param object $json
     * @param bool   $ov
     *
     * @return Result
     */
    protected function getBestSource($json, $ov = false)
    {
        $result = new Result();

        foreach ($json->videoJsonPlayer->VSR as $source) {
            $this->getLogger()->log(
                sprintf(
                    'found quality of %d with language %s (%s)',
                    $source->bitrate,
                    $source->versionLibelle,
                    $source->versionShortLibelle
                )
            );

            $shortLibelleLowercase = mb_strtolower($source->versionShortLibelle);

            if ($source->mediaType === 'mp4' &&
                $source->bitrate > $result->getBitrateRating() &&
                (
                    (
                        !$ov &&
                        $this->shortLibelleMatches($shortLibelleLowercase)
                    ) ||
                    (
                        $ov &&
                        $this->shortLibelleIsOv($shortLibelleLowercase)
                    )
                )
            ) {
                $result = new Result();

                $result->setBitrateRating($source->bitrate);
                $result->setUri($source->url);
            }
        }

        return $result;
    }

    /**
     * @param string|array $shortLibelle
     *
     * @return bool
     */
    protected function shortLibelleMatches($shortLibelle)
    {
        if (!is_array($this->languageShortLibelle)) {
            return $shortLibelle === $this->languageShortLibelle;
        }

        return in_array($shortLibelle, $this->languageShortLibelle, true);
    }

    /**
     * @param string $shortLibelle
     *
     * @return bool
     */
    protected function shortLibelleIsOv($shortLibelle)
    {
        return in_array($shortLibelle, self::$OV_SHORT_LIBELLE, true);
    }

    /**
     * @param Result $result
     * @param object $json
     *
     * @return Result
     */
    protected function addTitle($result, $json)
    {
        $result->setTitle(trim($json->videoJsonPlayer->VTI));
        $result->setEpisodeTitle(isset($json->videoJsonPlayer->VSU) ? trim($json->videoJsonPlayer->VSU) : '');

        return $result;
    }

    /**
     * @param string|null $detectedLanguage
     *
     * @return bool
     */
    protected function languageExists($detectedLanguage)
    {
        if ($detectedLanguage === null) {
            return false;
        }

        return isset(
            self::$LANGUAGE_MAP[$detectedLanguage],
            self::$LANGUAGE_MAP_SHORT_LIBELLE[$detectedLanguage]
        );
    }
}
