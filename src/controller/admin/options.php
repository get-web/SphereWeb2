<?php
/**
 * Created by Logan22
 * Github -> https://github.com/Cannabytes/SphereWeb
 * Date: 17.08.2022 / 12:13:03
 */

namespace Ofey\Logan22\controller\admin;

use DateTime;
use Ofey\Logan22\component\alert\board;
use Ofey\Logan22\component\chronicle\client;
use Ofey\Logan22\component\fileSys\fileSys;
use Ofey\Logan22\component\lang\lang;
use Ofey\Logan22\component\redirect;
use Ofey\Logan22\component\servername\servername;
use Ofey\Logan22\component\sphere\type;
use Ofey\Logan22\component\time\time;
use Ofey\Logan22\component\time\timezone;
use Ofey\Logan22\model\admin\patchlist;
use Ofey\Logan22\model\admin\server;
use Ofey\Logan22\model\admin\update_cache;
use Ofey\Logan22\model\admin\validation;
use Ofey\Logan22\model\db\sql;
use Ofey\Logan22\model\install\install;
use Ofey\Logan22\model\server\serverModel;
use Ofey\Logan22\model\user\auth\auth;
use Ofey\Logan22\model\user\user;
use Ofey\Logan22\template\tpl;

class options
{

    //POST
    public static function delete_server(): void
    {
        $server_id = $_POST['serverId'] ?? board::error("Server id is empty");

        $serverInfo = \Ofey\Logan22\model\server\server::getServer($server_id);
        if ($serverInfo->getId() != $server_id) {
            redirect::location("/admin/server/list");
        }

        $servers_id = \Ofey\Logan22\component\sphere\server::send(type::SERVER_LIST)->show(false)->getResponse();
        if (isset($servers_id['error']) or $servers_id === null) {
            $sphereAPIError        = true;
            $servers_id['servers'] = [];
        }

        foreach ($servers_id['ids'] as $sid) {
            if ($serverInfo->getId() == $sid) {
                $response = \Ofey\Logan22\component\sphere\server::send(type::DELETE_SERVER, [
                  "id" => (int)$sid,
                ])->show()->getResponse();
                if ($response['success']) {
                    sql::run("DELETE FROM `servers` WHERE `id` = ?", [$sid]);
                    board::redirect("/admin/server/list");
                    board::success("Сервер удален");
                }
            }
        }

        sql::run("DELETE FROM `servers` WHERE `id` = ?", [$server_id]);
        board::redirect("/admin/server/list");
        board::success("Сервер удален");
    }

    //POST - Регистрация сервера
    public static function create_server(): void
    {
        $name            = $_POST['name'] ?? "Bartz_" . random_int(1, 999);
        $rateExp         = $_POST['rateExp'] ?? 1;
        $rateSp          = $_POST['rateSp'] ?? 1;
        $rateAdena       = $_POST['rateAdena'] ?? 1;
        $rateDrop        = $_POST['rateDrop'] ?? 1;
        $rateSpoil       = $_POST['rateSpoil'] ?? 1;
        $version_client  = $_POST['version_client'] ?? board::error("No select  client");
        $sql_base_source = $_POST['sql_base_source'] ?? board::error("No select L2j SQL base");

        $login_host     = $_POST['login_host'] ?? board::error("No select login host");
        $login_port     = $_POST['login_port'] ?? 3306;
        $login_user     = $_POST['login_user'] ?? board::error("No select login user");
        $login_password = $_POST['login_password'] ?? board::error("No select login password");
        $login_name     = $_POST['login_name'] ?? board::error("No select login name");

        $game_host     = $_POST['game_host'] ?? board::error("No select game host");
        $game_port     = $_POST['game_port'] ?? 3306;
        $game_user     = $_POST['game_user'] ?? board::error("No select game user");
        $game_password = $_POST['game_password'] ?? board::error("No select game password");
        $game_name     = $_POST['game_name'] ?? board::error("No select game name");

        $data = \Ofey\Logan22\component\sphere\server::send(type::ADD_NEW_SERVER, [

          "login_host"     => $login_host,
          "login_port"     => $login_port,
          "login_user"     => $login_user,
          "login_password" => $login_password,
          "login_name"     => $login_name,

          "game_host"     => $game_host,
          "game_port"     => $game_port,
          "game_user"     => $game_user,
          "game_password" => $game_password,
          "game_name"     => $game_name,

          "collection" => $sql_base_source,

        ])->show()->getResponse();

        if (isset($data['id'])) {
            $id   = $data['id'];
            $data = [
              "id"        => $id,
              "name"      => $name,
              "rateExp"   => $rateExp,
              "rateSp"    => $rateSp,
              "rateAdena" => $rateAdena,
              "rateDrop"  => $rateDrop,
              "rateSpoil" => $rateSpoil,
              "chronicle" => $version_client,
              "source"    => $sql_base_source,
            ];
            sql::run("INSERT INTO `servers` (`id`, `data`) VALUES (?, ?)", [$id, json_encode($data)]);
            board::redirect("/admin/server/list");
            board::success(lang::get_phrase(243));
        }

        board::error(lang::get_phrase("error"));
    }

    // Обновление коллекции
    static public function updateCollection(): void
    {
        $response = \Ofey\Logan22\component\sphere\server::send(type::UPDATE_COLLECTION, [
          "id"   => (int)$_POST['serverId'],
          "name" => $_POST['collection'],
        ])->show(true)->getResponse();
        if ($response['success']) {
            $server = \Ofey\Logan22\model\server\server::getServer((int)$_POST['serverId']);
            $server->setChronicle($_POST['version_client']);
            $server->setCollection($_POST['collection']);
            $server->save();
            board::success("Коллекция обновлена");
        }
    }

    static public function edit_server_show($id = null): void
    {
        validation::user_protection("admin");

        $servers = \Ofey\Logan22\model\server\server::getServerAll();
        if ( ! $servers) {
            redirect::location("/admin/server/add/new");
        }

        //Подгрузка списокв серверов с сервера сферы
        $serversFullInfo = \Ofey\Logan22\component\sphere\server::send(type::SERVER_FULL_INFO)->showErrorPageIsOffline()->getResponse();
        if ($serversFullInfo['error']) {
            redirect::location("/admin/server/list");
        }

        $serversFullInfo = $serversFullInfo['servers'];
        if ($id) {
            $serverInfo = \Ofey\Logan22\model\server\server::getServer($id);
            if ($serverInfo->getId() != $id) {
                redirect::location("/admin/server/list");
            }

            foreach ($serversFullInfo as $serverInfoData) {
                if ($serverInfo->getId() == $serverInfoData['id']) {
                    $serverInfo->setIsSphereServer(true);
                    $serverInfo->getStatus()->setDisabled($serverInfoData['disabled']);
                }
            }
            tpl::addVar([
              'serverInfo' => $serverInfo,
            ]);
        }

        if ($serversFullInfo != null) {
            foreach ($servers as &$server) {
                foreach ($serversFullInfo as $serverInfoData) {
                    if ($server->getId() == $serverInfoData['id']) {
                        $server->setIsSphereServer(true);
                        $server->getStatus()->setDisabled($serverInfoData['disabled']);
                    }
                }
            }

            $arrayUniq = self::filterUniqueIds(\Ofey\Logan22\model\server\server::getServerAll(), $serversFullInfo);
            foreach ($arrayUniq as $id) {
                $sm = new serverModel([
                  'id' => $id,
                ]);
                sql::run("INSERT INTO `servers` (`id`, `data`) VALUES (?, ?)", [$id, json_encode(['id' => $id])]);
                $sphereServers = $sm;
                $servers[]     = $sphereServers;
            }
        }

        $mysql_data_connect = sql::getRow(
          "SELECT * FROM `server_cache` WHERE server_id = ? and `type`='mysql_data_connect';",
          [$server->getId()]
        );
        $mysql_data_connect = json_decode($mysql_data_connect['data'], true);

        tpl::addVar([
          'mysql_data_connect'    => $mysql_data_connect,
          'sphereServers'         => $servers,
          'client_list_default'   => client::all(),
          'timezone_list_default' => timezone::all(),
          "title"                 => lang::get_phrase(221),
        ]);
        tpl::display("/admin/server_list.html");
    }

    static private function filterUniqueIds($objects, $serversFullInfo): array
    {
        // Получаем все ID из первого массива
        $objectIds = array_map(function ($object) {
            return $object->getId(); // Предполагается, что в классе есть метод getId()
        }, $objects);

        // Получаем массив только с ID из $serversFullInfo
        $ids = array_map(function ($server) {
            return $server['id'];
        }, $serversFullInfo);
        // Фильтруем второй массив, исключая ID, которые есть в первом массиве
        $filteredIds = array_filter($ids, function ($id) use ($objectIds) {
            return ! in_array($id, $objectIds);
        });

        return $filteredIds;
    }

    public static function saveGeneral(): void
    {
        $server_id = $_POST['serverId'] ?? board::error("Server id is empty");
        $data      = json_encode([
          "id"        => $server_id,
          "name"      => $_POST['name'] ?? board::error("Server name is empty"),
          "rateExp"   => $_POST['rateExp'] ?? board::error("Server rateExp is empty"),
          "rateSp"    => $_POST['rateSp'] ?? board::error("Server rateSp is empty"),
          "rateAdena" => $_POST['rateAdena'] ?? board::error("Server rateAdena is empty"),
          "rateDrop"  => $_POST['rateDrop'] ?? board::error("Server rateDrop is empty"),
          "rateSpoil" => $_POST['rateSpoil'] ?? board::error("Server rateSpoil is empty"),
          "chronicle" => $_POST['version_client'] ?? board::error("Server chronicle is empty"),
          "source"    => $_POST['sql_base_source'] ?? board::error("Server source is empty"),
        ], JSON_UNESCAPED_UNICODE);
        sql::run(
          "UPDATE `servers` SET `data` = ? WHERE `id` = ?",
          [
            $data,
            $server_id,
          ]
        );

        if (isset($_POST['statusServer'])) {
            $status = $_POST['statusServer'];
            if ($status['enableStatusServer']) {
                $loginServerPort = filter_var(
                  $status['statusLoginServerPort'],
                  FILTER_VALIDATE_INT
                ) ? (int)$status['statusLoginServerPort'] : -1;
                $gameServerPort  = filter_var(
                  $status['statusGameServerPort'],
                  FILTER_VALIDATE_INT
                ) ? (int)$status['statusGameServerPort'] : -1;

                \Ofey\Logan22\component\sphere\server::setServer($server_id);
                $data = \Ofey\Logan22\component\sphere\server::send(type::UPDATE_STATUS_SERVER, [
                  "enableStatusServer"    => filter_var($status['enableStatusServer'], FILTER_VALIDATE_BOOLEAN),
                  "statusLoginServerIP"   => $status['statusLoginServerIP'] ?? "",
                  "statusLoginServerPort" => $loginServerPort,
                  "statusGameServerIP"    => $status['statusGameServerIP'] ?? "",
                  "statusGameServerPort"  => $gameServerPort,
                ])->show()->getResponse();
            }
        }

        $server = \Ofey\Logan22\model\server\server::getServer($server_id);
        $server->getStatus(true);

        board::success(lang::get_phrase(217));
    }

    public static function saveOther(): void
    {
        $server_id      = $_POST['serverId'];
        $start_time     = $_POST['date_start_server'];
        $max_online     = $_POST['max_online'] ?? board::error("Server max_online is empty");
        $knowledge_base = $_POST['knowledge_base'] ?? board::error("Server knowlege_base is empty");
        $knowledge_base = fileSys::modifyString($knowledge_base);
        $resetHWID      = $_POST['resetHWID'] ?? false;
        $timezone       = $_POST['timezone'] ?? board::error("Server timezone is empty");

        if (empty($start_time)) {
            $startDate = time::mysql();
            // У меня дата такого формата May 29, 2024 16:00
            $dateTime  = DateTime::createFromFormat('Y-m-d H:i:s', $startDate);
            $startDate = $dateTime->modify('+2 months')->format('Y-m-d H:i:s');
        } else {
            $dateTime  = DateTime::createFromFormat('Y-m-d H:i', $start_time);
            $startDate = $dateTime->format('Y-m-d H:i:s');
        }

        //Удаление старых данных
        sql::run("DELETE FROM `server_data` WHERE `key` = ? AND `server_id` = ?;", ['date_start_server', $server_id]);
        sql::run("INSERT INTO `server_data` (`key`, `val`, `server_id` ) VALUES (?, ?, ?);", ['date_start_server', $startDate, $server_id]);

        sql::run("DELETE FROM `server_data` WHERE `key` = ? AND `server_id` = ?;", ['max_online', $server_id]);
        sql::run("INSERT INTO `server_data` (`key`, `val`, `server_id` ) VALUES (?, ?, ?);", ['max_online', $max_online, $server_id]);

        sql::run("DELETE FROM `server_data` WHERE `key` = ? AND `server_id` = ?;", ['knowledge_base', $server_id]);
        sql::run("INSERT INTO `server_data` (`key`, `val`, `server_id` ) VALUES (?, ?, ?);", ['knowledge_base', $knowledge_base, $server_id]
        );

        sql::run("DELETE FROM `server_data` WHERE `key` = ? AND `server_id` = ?;", ['resetHWID', $server_id]);
        sql::run("INSERT INTO `server_data` (`key`, `val`, `server_id` ) VALUES (?, ?, ?);", ['resetHWID', $resetHWID, $server_id]);

        sql::run("DELETE FROM `server_data` WHERE `key` = ? AND `server_id` = ?;", ['timezone', $server_id]);
        sql::run("INSERT INTO `server_data` (`key`, `val`, `server_id` ) VALUES (?, ?, ?);", ['timezone', $timezone, $server_id]);

        board::success(lang::get_phrase(217));
    }

    //Сохранение настроек для подключения к базе данных MySQL
    static public function saveMySQL(): void
    {
        $server_id      = $_POST['serverId'];
        $login_host     = $_POST['login_host'] ?? board::error("No select login host");
        $login_port     = $_POST['login_port'] ?? 3306;
        $login_user     = $_POST['login_user'] ?? board::error("No select login user");
        $login_password = $_POST['login_password'] ?? "";
        $login_name     = $_POST['login_name'] ?? board::error("No select login name");

        $game_host     = $_POST['game_host'] ?? board::error("No select game host");
        $game_port     = $_POST['game_port'] ?? 3306;
        $game_user     = $_POST['game_user'] ?? board::error("No select game user");
        $game_password = $_POST['game_password'] ?? "";
        $game_name     = $_POST['game_name'] ?? board::error("No select game name");

        $arr = [
          "login_host"     => $login_host,
          "login_port"     => $login_port,
          "login_user"     => $login_user,
          "login_password" => $login_password,
          "login_name"     => $login_name,

          "game_host"     => $game_host,
          "game_port"     => $game_port,
          "game_user"     => $game_user,
          "game_password" => $game_password,
          "game_name"     => $game_name,

          "serverId" => $server_id,
        ];

        $data = \Ofey\Logan22\component\sphere\server::send(type::CONNECT_DB_UPDATE, $arr)->show()->getResponse();

        $save_data_MySQL = filter_var($_POST['save_data_MySQL'], FILTER_VALIDATE_BOOL);
        sql::sql("DELETE FROM `server_cache` WHERE `server_id` = ? AND `type` = 'mysql_data_connect'", [$server_id]);
        if ($save_data_MySQL) {
            $jsonData = json_encode($arr);
            sql::sql("INSERT INTO `server_cache` ( `server_id`, `type`, `data`, `date_create`) VALUES (?, ?, ?, ?)", [
              $server_id,
              'mysql_data_connect',
              $jsonData,
              time::mysql(),
            ]);
        }

        board::success(lang::get_phrase(217));
    }

    static public function new_server(): void
    {
        validation::user_protection("admin");
        tpl::addVar([
          'servername_list_default' => servername::all(),
          'client_list_default'     => client::all(),
          'timezone_list_default'   => timezone::all(),
          "title"                   => lang::get_phrase(221),
        ]);
        tpl::display("/admin/server_add.html");
    }

    static public function server_show()
    {
        validation::user_protection("admin");
        tpl::addVar([
          'servername_list_default' => servername::all(),
          'client_list_default'     => client::all(),
          'timezone_list_default'   => timezone::all(),
          "donateSysNames"          => self::AllDonateSystem(),
        ]);
        tpl::display("/admin/setting.html");
    }

    private static function AllDonateSystem()
    {
        $all_donate_system = fileSys::get_dir_files("src/component/donate", [
          'basename' => true,
          'fetchAll' => true,
        ]);

        $donateSysNames = [];
        foreach ($all_donate_system as $system) {
            if ( ! $system::isEnable()) {
                continue;
            }
            if (method_exists($system, 'forAdmin')) {
                if ($system::forAdmin() and auth::get_access_level() != 'admin') {
                    continue;
                }
            }
            $inputs = [];
            if (method_exists($system, 'inputs')) {
                $inputs = $system::inputs();
            }
            if (method_exists($system, 'getDescription')) {
                $donateSysNames[] = [
                  'name'   => basename($system),
                  'desc'   => $system::getDescription(),
                  'inputs' => $inputs,
                ];
            } else {
                $donateSysNames[] = [
                  'name'   => basename($system),
                  'desc'   => basename($system),
                  'inputs' => $inputs,
                ];
            }
        }

        return $donateSysNames;
    }

    public static function saveConfigDonate(): void
    {
        $post = json_encode($_POST, JSON_UNESCAPED_UNICODE);
        if ( ! $post) {
            board::error("Ошибка парсинга JSON");
        }
        $data = json_decode($post, true);
        foreach ($data['donateSystems'] as $i => $system) {
            $sysData = reset($system);
            if ( ! $sysData['inputs']) {
                unset($data['donateSystems'][$i]);
            }
        }
        $post = json_encode($data, JSON_UNESCAPED_UNICODE);
        sql::sql("DELETE FROM `settings` WHERE `key` = '__config_donate__' AND serverId = ? ", [
          user::self()->getServerId(),
        ]);
        sql::run("INSERT INTO `settings` (`key`, `setting`, `serverId`, `dateUpdate`) VALUES ('__config_donate__', ?, ?, ?)", [
          $post,
          user::self()->getServerId(),
          time::mysql(),
        ]);
        board::success("Настройки сохранены");
    }

    public static function saveConfigReferral(): void
    {
        $post = json_encode($_POST);
        if ( ! $post) {
            board::error("Ошибка парсинга JSON");
        }
        sql::sql("DELETE FROM `settings` WHERE `key` = '__config_referral__' AND serverId = ? ", [
          user::self()->getServerId(),
        ]);
        sql::run("INSERT INTO `settings` (`key`, `setting`, `serverId`, `dateUpdate`) VALUES ('__config_referral__', ?, ?, ?)", [
          $post,
          user::self()->getServerId(),
          time::mysql(),
        ]);
        board::success("Настройки сохранены");
    }

    public static function test_connect_db()
    {
        validation::user_protection("admin");
        if (install::test_connect_mysql(
          $_POST['host'] ?? "127.0.0.1",
          $_POST['port'] ?? 3306,
          $_POST['userModel'] ?? "root",
          $_POST['password'] ?? "",
          $_POST['name'] ?? ""
        )) {
            board::notice(true, lang::get_phrase(222));
        } else {
            board::notice(false, lang::get_phrase(223));
        }
    }

    //Проверка соединения с базой данных игрового сервера MySQL
    public static function test_connect_db_selected_name()
    {
        validation::user_protection("admin");
        $data = \Ofey\Logan22\component\sphere\server::send(type::CONNECT_DB, [
          "host"     => $_POST['host'],
          "port"     => $_POST['port'],
          "user"     => $_POST['user'],
          "password" => $_POST['password'],
        ])->getResponse();
        if (isset($data["databases"])) {
            board::alert([
              'type'      => 'notice',
              'ok'        => true,
              'message'   => "Соединение с базой данных успешно",
              'databases' => $data["databases"],
            ]);

            return;
        }
        board::alert([
          'type'    => 'notice',
          'ok'      => false,
          'message' => "Не удалось соединиться с базой данных",
        ]);
    }

    public static function server_list()
    {
        validation::user_protection("admin");
        tpl::display("/admin/server/servers.html");
    }

    public static function description_save()
    {
        validation::user_protection("admin");
        server::add_description();
    }

    public static function description_default_page_save()
    {
        validation::user_protection("admin");
        server::description_default();
    }

    public static function cache_save()
    {
        validation::user_protection("admin");
        update_cache::save();
    }

}













