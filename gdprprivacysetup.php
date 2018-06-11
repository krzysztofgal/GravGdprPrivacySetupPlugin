<?php

/**
 * GravGdprPrivacySetupPlugin
 * https://github.com/krzysztofgal/GravGdprPrivacySetupPlugin
 *
 * Copyright 2018 Krzysztof Gał
 * Released under the MIT license
 */

namespace Grav\Plugin;

use Grav\Common\Grav;
use Grav\Common\Page\Page;
use Grav\Common\Page\Pages;
use Grav\Common\Uri;
use Grav\Common\Plugin;

/**
 * Class GdprPrivacySetupPlugin
 * @package Grav\Plugin
 */
class GdprPrivacySetupPlugin extends Plugin
{
    private $current_route;
    private $privacy_info_route;
    private $csp;
    private $cookieName;
    private $userConsent;

    public static function getSubscribedEvents()
    {
        return [
            'onPluginsInitialized' => ['onPluginsInitialized', 0],
            'onTwigLoader' => ['onTwigLoader', 0],
        ];
    }

    public function onPluginsInitialized()
    {
        if ($this->isAdmin()) {
            $this->active = false;

            $this->config->set('plugins.gdprprivacysetup.privacySHA1', self::getPrivacyInfoSha1());
            $this->saveConfig($this->name);

            return;
        }

        $this->active = $this->config->get('plugins.gdprprivacysetup.enabled');

        if ($this->active) {
            $this->privacy_info_route = $this->config->get('plugins.gdprprivacysetup.privacy_page_route');

            //get content security policies setup
            $this->csp = $this->config->get('plugins.gdprprivacysetup.consentPolicyList');

            //set hashed cookie name from information page, to know if user should accept changed policy
            if (!$this->config->get('plugins.gdprprivacysetup.privacySHA1')) {
                $this->config->set('plugins.gdprprivacysetup.privacySHA1', self::getPrivacyInfoSha1());
                $this->saveConfig($this->name);
            }

            $this->cookieName = 'consent_' . $this->config->get('plugins.gdprprivacysetup.privacySHA1');

            //try to get saved user consent
            $this->getUserConsent();

            $this->enable([
                'onPagesInitialized' => ['onPagesInitialized', 1000],
                'onTwigSiteVariables' => ['onTwigSiteVariables', 0]
            ]);
        }
    }

    /**
     * Get saved user consent or get default settings
     */
    private function getUserConsent()
    {
        if (isset($_COOKIE[$this->cookieName])) {
            try {
                $this->userConsent = json_decode($_COOKIE[$this->cookieName], true);
            } catch (\Exception $e) {
                $this->userConsent = $this->getDefaultConsents();
            }
        } else {
            $this->userConsent = $this->getDefaultConsents();
        }
    }

    /**
     * @return array
     */
    private function getDefaultConsents()
    {
        $policies = $this->csp;
        if (is_array($policies)) {
            return array_column($policies, 'default', 'consent');
        }
        return [];
    }

    /**
     * @throws \Exception
     */
    public function onPagesInitialized()
    {
        /** @var Uri $uri */
        $uri = $this->grav['uri'];
        $this->current_route = $uri->path();

        /**
         * @var $pages Pages;
         */
        $pages = $this->grav['pages'];

        $page = $pages->dispatch($this->current_route);

        if (!$page && $this->privacy_info_route == $this->current_route) {
            $page = new Page;
            $page->init(new \SplFileInfo(__DIR__ . "/pages/privacy_info.md"));
            $page->slug(basename($this->current_route));
            $pages->addPage($page, $this->current_route);
        }

        $page->metadata([
            'gdprCSP' => [
                'http_equiv' => "Content-Security-Policy",
                'content' => $this->getCspRules()
            ],
        ]);
    }

    /** Make CSP meta tag content
     * @return string
     */
    private function getCspRules()
    {
        $policy = '';
        foreach ($this->csp as $rule) {
            if ($this->isAllowed($rule['consent']) && !empty($rule['policy'])) {
                $policy .= $rule['policy'] . ' ';
            }
        }

        return $policy . ';';
    }

    /**
     * @param $consent
     * @return bool
     */
    private function isAllowed($consent)
    {
        if (!isset($this->userConsent[$consent])) {
            //fallback for new consents, that are not in cookie
            $this->userConsent[$consent] = $this->csp[$consent] ?? false;
        }

        return (bool) $this->userConsent[$consent];
    }

    public function onTwigSiteVariables()
    {
        $this->grav['assets']->addCss('plugin://gdprprivacysetup/assets/css/tingle.min.css');
        $this->grav['assets']->addJs('plugin://gdprprivacysetup/assets/js/js.cookie.js');
        $this->grav['assets']->addJs('plugin://gdprprivacysetup/assets/js/tingle.min.js');
        $this->grav['assets']->addJs('plugin://gdprprivacysetup/assets/js/gdprprivacysetup.js');

        $privacyPage = $this->privacy_info_route;
        $consentButtonText = $this->config->get('plugins.gdprprivacysetup.consentButtonText');
        $consentButtonClass = $this->config->get('plugins.gdprprivacysetup.consentButtonClass');

        $denyButtonText = $this->config->get('plugins.gdprprivacysetup.denyButtonText');
        $denyButtonClass = $this->config->get('plugins.gdprprivacysetup.denyButtonClass');

        $privacySettingsBtnClass = $this->config->get('plugins.gdprprivacysetup.privacySettingsBtnClass');

        $inputPrefix = $this->config->get('plugins.gdprprivacysetup.inputPrefix');
        $modalWindow = $this->config->get('plugins.gdprprivacysetup.modalWindowId');

        $denyRedirection = $this->config->get('plugins.gdprprivacysetup.denyRedirectionTarget');

        $deferInfoPopupTime = $this->config->get('plugins.gdprprivacysetup.deferInfoPopupTime');
        $consentExpires = $this->config->get('plugins.gdprprivacysetup.consentExpiresTime');

        $cookieName = $this->cookieName;

        try {
            $userConsent = json_encode($this->userConsent);
        } catch (\Exception $e) {
            $userConsent = "{}";
        }

        $init = "var gdprPrivacySetupPluginSettings = {
            setupPage: \"${privacyPage}\",
            setupConsent: \"${consentButtonText}\",
            denyConsent: \"${denyButtonText}\",
            inputPrefix: \"${inputPrefix}\",
            modalContentId: \"${modalWindow}\",
            cookieName: \"${cookieName}\",
            privacySettingsButtonClass: \"${privacySettingsBtnClass}\",
            acceptBtnClass: \"${consentButtonClass}\",
            denyBtnClass: \"${denyButtonClass}\",
            denyRedirectionTarget: \"${denyRedirection}\",
            deferInfoPopup: ${deferInfoPopupTime},
            consentExpires: ${consentExpires},
            userConsent: ${userConsent}
        };
        gdprPrivacySetupPlugin.init(gdprPrivacySetupPluginSettings);";

        $this->grav['assets']->addInlineJs($init);
    }

    /**
     * Add the Twig template paths to the Twig loader
     */
    public function onTwigLoader()
    {
        $this->grav['twig']->addPath(__DIR__ . '/templates');
    }

    /** Get sha1 hash of privacy information page, to know if visitor accepted current version of privacy policy
     * @return string
     */
    public static function getPrivacyInfoSha1()
    {
        $grav = Grav::instance();

        $config = $grav['config'];

        return sha1($config->get('plugins.gdprprivacysetup.privacyInfo'));
    }
}
