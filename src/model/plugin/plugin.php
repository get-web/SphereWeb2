<?php

namespace Ofey\Logan22\model\plugin;

use Ofey\Logan22\component\alert\board;
use Ofey\Logan22\component\time\time;
use Ofey\Logan22\model\db\sql;
use Ofey\Logan22\model\forum\forumStruct;
use Ofey\Logan22\model\user\user;
use Ofey\Logan22\template\tpl;

class plugin
{

    private static ?array $plugins = null;

    static public function getPlugins(): array
    {
        if (self::$plugins == null) {
            self::loading();
        }
        return self::$plugins;
    }

    static public function loading(): void
    {
        $pluginList = [];
        $configData = sql::getRow(
          "SELECT * FROM `settings` WHERE `key` = '__PLUGIN__'"
        );
        $serverId = user::self()->getServerId();

        if ($configData) {
            $plugins = json_decode($configData['setting'], true);

            $pluginKeys = array_map(fn($plugin) => "'__PLUGIN__{$plugin}'", $plugins);
            $inClause = implode(',', $pluginKeys);
            $settings = sql::getRows("SELECT * FROM `settings` WHERE `key` IN ($inClause) AND serverId = ?", [$serverId]);

            foreach ($settings as $setting) {
                $plugin = str_replace('__PLUGIN__', '', $setting['key']);
                $data = json_decode($setting['setting'], true);



                $pluginSetting = new DynamicPluginSetting();
                $pluginSetting->pluginName = $plugin;
                $pluginSetting->pluginServerId = $serverId;

                foreach (tpl::pluginsAll() as $key => $value) {
                    if ($value['PLUGIN_DIR_NAME'] == $plugin) {
                        foreach ($value as $k => $v) {
                            $pluginSetting->setPluginData($k, $v);
                        }
                    }
                }

                foreach ($data as $key => $value) {
                    $pluginSetting->$key = $value;
                }
                $pluginList[$plugin] = $pluginSetting;
            }
        }
        self::$plugins = $pluginList;
    }


    static public function getPluginActive($name = null)
    {
        if ($name === null) {
            return self::getAllPlugins();
        }
        return self::$plugins[$name] ?? false;
    }

    static public function getAllPlugins()
    {
        return self::$plugins;
    }

    public static function saveSetting()
    {
        $pluginConfig = self::get($_POST['pluginName']);
        $pluginConfig->save();
    }

    // Сохранение данных плагина
    public static function __save_activator_plugin(): void
    {
        $pluginName = $_POST['pluginName'] ?? null;
        $setting    = $_POST['setting'] ?? null;
        $value      = $_POST['value'] ?? null;

        if (!$pluginName || !$setting) {
            return;
        }

        $serverId = user::self()->getServerId();

        if ($setting === 'enablePlugin') {
            $isEnabled = filter_var($value, FILTER_VALIDATE_BOOL);

            if ($isEnabled && !in_array($pluginName, self::$plugins)) {
                self::$plugins[] = $pluginName;
            } elseif (!$isEnabled) {
                unset(self::$plugins[array_search($pluginName, self::$plugins)]);
            }

            sql::sql("DELETE FROM `settings` WHERE `key` = ? AND `serverId` = ?", ['__PLUGIN__', $serverId]);
            sql::run(
              "INSERT INTO `settings` (`key`, `setting`, `serverId`, `dateUpdate`) VALUES (?, ?, ?, ?)",
              ['__PLUGIN__', json_encode(self::$plugins, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $serverId, time::mysql()]
            );
            board::success("OK");
        }
    }

    public static function get(string $getNameClass) : DynamicPluginSetting {
        if (self::$plugins[$getNameClass]){
            return self::$plugins[$getNameClass];
        }
        $pl = new DynamicPluginSetting();
        $pl->pluginName = $getNameClass;
        $pl->pluginServerId = user::self()->getServerId();
        return $pl;
    }

    public static function getSetting(string $getNameClass) {
        $pluginData = self::get($getNameClass)->getPluginData();
        $customData = self::get($getNameClass)->getAllData();
        return array_merge($pluginData, $customData);
    }


}

