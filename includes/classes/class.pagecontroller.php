<?php

/**
 * Copyright (C) 2011 Shadez <https://github.com/Shadez>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 **/

Class PageController {
    protected $m_url = '';          // absolute URL (/wow/en/character/Armory%20Realm/Name/advanced)
    protected $m_controller = '';   // controller name (Character)
    protected $m_type = '';         // BNet, Account, WoW
    protected $m_actions = array(); // array of actions (character, Armory%20Realm, Name, advanced)
    protected $m_errorCode = 0;     // 403, 404, 500 and others HTTP error statuses.
    protected $m_locale = 'en';     // locale name from URL
    protected $m_locale_index = 0;  // locale index in URL
    protected $m_skip_redirect = false;
    protected $m_allowErrorPage = true;
    
    public function __construct() {
        // Check maintenance
        if(WoWConfig::$Maintenance) {
            $this->m_controller = 'Maintenance';
            $this->m_type = 'bn';
            $this->LoadController();
            exit;
        }
        $this->FindActions();
    }
    
    public function Init() {
        if(preg_match('/\/static\//', $this->m_url)) {
            return;
        }
        $this->ParseURL();
        $this->LoadController();
    }
    
    public function FindActions() {
        $this->m_url = $_SERVER['REQUEST_URI'];
        $url_data = explode('/', $this->m_url);
        if(!$url_data || !is_array($url_data)) {
            WoW_Template::ErrorPage(404);
            return false;
        }
        $url_size = sizeof($url_data);
        if(!$url_size) {
            return false;
        }
        switch(strtolower($url_data[1])) {
            case 'wow':
            case 'login':
                $this->m_type = strtolower($url_data[1]);
                $this->m_locale_index = 2;
                break;
            case 'account':
            case 'marketing':
            case 'ta':
                $this->m_type = strtolower($url_data[1]);
                $this->m_locale_index = 0;
                $this->m_skip_redirect = true;
                break;
            default:
                $this->m_type = 'bn';
                $this->m_locale_index = 1;
                break;
        }
        $allowed_locales = array('de', 'en', 'es', 'fr', 'ru');
        for($i = 1; $i < $url_size; ++$i) {
            $this->m_actions['action' . ($i - 1)] = $url_data[$i];
            if(in_array($url_data[$i], $allowed_locales)) {
                $this->m_locale = $url_data[$i];
            }
        }
        if($this->m_type == 'account') {
            $this->m_locale = $_COOKIE['wow_locale'];
        }
    }
    
    private function ParseURL() {
        $url_data = explode('/', $this->m_url);
        $allowed_locales = array('de', 'en', 'es', 'fr', 'ru');
        if((!isset($url_data[$this->m_locale_index]) || $url_data[$this->m_locale_index] === null || !in_array($url_data[$this->m_locale_index], $allowed_locales)) && !$this->m_skip_redirect) {
            $sTmp = substr($this->m_url, 1, strlen($url_data[1]));
            unset($url_data[0]);
            if($this->m_type == 'wow') {
                unset($url_data[1]);
                $newUrl =  WoW::GetWoWPath(). 'wow/' . $_COOKIE['wow_locale'] . '/' . implode('/', $url_data);
            }
            elseif($this->m_type == 'login') {
                unset($url_data[1]);
                $newUrl =  WoW::GetWoWPath(). 'login/' . $_COOKIE['wow_locale'] . '/' . implode('/', $url_data);
            }
            else {
                $newUrl = $_COOKIE['wow_locale'] . '/' . implode('/', $url_data);
            }
            $newUrl = str_replace('//', '/', $newUrl);
            WoW::RedirectTo($newUrl);
        }
        else {
            $this->m_locale = $_COOKIE['wow_locale'];
        }
        $this->m_controller = 'home';
        if(isset($url_data[$this->m_locale_index + 1])) {
            $exploded = explode('?', $url_data[$this->m_locale_index + 1]);
            if($exploded) {
                $this->m_controller = $exploded[0] != null ? $exploded[0] : 'home';
            }
            else {
                $this->m_controller = $url_data[$this->m_locale_index + 1];
            }
        }
        $this->m_controller = str_replace(array('-', '.'), '_', $this->m_controller);
        return true;
    }
    
    private function LoadController() {
        if(!$this->m_controller) {
            WoW_Log::WriteError('%s : unable to detect controller name (type: %s)!', __METHOD__, $this->m_type);
            WoW_Template::ErrorPage(404);
            return false;
        }
        $this->m_allowErrorPage = true;
        switch(strtolower($this->m_controller)) {
            case 'marketing':
            case 'ta':
            case 'login_frag':
            case 'data':
            case 'discussion':
            case 'pref':
                $this->m_allowErrorPage = false;
                break;
            case 'item':
                if(isset($this->m_actions['action4']) && strtolower($this->m_actions['action4']) == 'tooltip') {
                    $this->m_allowErrorPage = false;
                }
                break;
        }
        $controller_file = CONTROLLERS_DIR . $this->m_type . DS . $this->m_controller . '.php';
        if(!file_exists($controller_file)) {
            if($this->m_allowErrorPage) {
                WoW_Template::ErrorPage(404, null, ($this->m_type == 'wow' ? false : true));
            }
            exit;
        }
        include($controller_file);
        if(!class_exists($this->m_controller, false)) {
            if($this->m_allowErrorPage) {
                WoW_Template::ErrorPage(404);
            }
            exit;
        }
        $this->m_controller = new $this->m_controller($this->m_actions, $this->m_type);
        if(!is_object($this->m_controller) || !method_exists($this->m_controller, 'main')) {
            if($this->m_allowErrorPage) {
                WoW_Template::ErrorPage(404, null, ($this->m_type == 'wow' ? false : true));
            }
            exit;
        }
        // Run page        
        $this->m_controller->main();
    }
    
    public function GetURL() {
        return $this->m_url;
    }
    
    public function GetLocale() {
        return $this->m_locale;
    }
    
    public function GetControllerName() {
        return is_object($this->m_controller) ? get_class($this->m_controller) : $this->m_controller;
    }
}
?>