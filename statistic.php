<?php
/**
 * Created by PhpStorm.
 * User: suren.danielyan
 * Date: 05/11/2018
 * Time: 22:00
 */

require_once "z_mysql.php";
require_once "errors.php";
require_once "system.php";
require_once "config_user.php";
require_once "config_system.php";

$all_data = file_get_contents('php://input');
$income_data = json_decode($all_data);

$params = $income_data->params;
$answer = ["user_id" => $income_data->user_id, "token" => $income_data->token, "lang_id" => $income_data->lang_id, "error" => 0, "info" => [], "command" => $params->command];

//$params = (object)["command" => "by_time", "from" => '2018-12-12', "to" => '2018-12-19'];
//$answer = ["user_id" => 1, "token" => '123456789', "lang_id" => 3, "error" => 0, "info" => [], "command" => $params->command];

$con = new Z_MySQL();
switch ($answer["command"]) {
    case "by_service":
        $check_end = CLIENT_STOP;
        $answer['info'] = $con->queryNoDML("SELECT `branchID` AS 'branch_id', `serviceID` AS 'service_ID', `serv_list`.`parentID` AS 'parent_id', `name` AS 'service_name', 
                                                    IFNULL(`serv_list`.`count`, `parent`.`count`) AS 'count', 
                                                    IFNULL(`serv_list`.`avgTime`, `parent`.`avgTime`) AS 'avg_time' 
                                                    FROM 
                                                    (SELECT `list`.`branchID`, `list`.`serviceID`, `list`.`parentID`, `list`.`name`, `serv`.`count`, `serv`.`avgTime` FROM 
                                                    (SELECT `branch_service`.`branchID` AS 'branchID', `services`.`serviceParentID` AS 'parentID', `services`.`serviceID` AS 'serviceID', `service_names`.`text` AS 'name' 
                                                    FROM `services` JOIN `branch_service` JOIN `service_names`
                                                    WHERE `branch_service`.`serviceID` = `services`.`serviceID`
                                                    AND `service_names`.`serviceNameID` = `services`.`serviceNameID`
                                                    AND `service_names`.`langID` = {$answer['lang_id']}) AS `list`
                                                    LEFT JOIN 
                                                    (SELECT `services`.`serviceID` AS 'serviceID', COUNT(`checks_stat`.`checkName`) AS 'count', AVG(`checks_stat`.`actionTime`-`checks_stat`.`prevTime`) AS 'avgTime'
                                                    FROM `services` JOIN `service_names` JOIN `checks_stat`
                                                    ON `checks_stat`.`checkStatusID` = {$check_end}
                                                    AND `services`.`serviceID` = `checks_stat`.`serviceID`
                                                    AND `services`.`serviceNameID` = `service_names`.`serviceNameID`
                                                    AND `service_names`.`langID` = {$answer['lang_id']}
                                                    AND `checks_stat`.`actionDate`>='{$params->from}' 
                                                    AND `checks_stat`.`actionDate`<='{$params->to}'
                                                    GROUP BY `services`.`serviceID`) AS `serv`
                                                    ON `serv`.`serviceID` = `list`.`serviceID`) AS `serv_list`
                                                    LEFT JOIN 
                                                    (SELECT `services`.`serviceParentID` AS 'parentID', COUNT(`checks_stat`.`checkName`) AS 'count', AVG(`checks_stat`.`actionTime`-`checks_stat`.`prevTime`) AS 'avgTime'
                                                    FROM `services` JOIN `service_names` JOIN `checks_stat`
                                                    ON `checks_stat`.`checkStatusID` = {$check_end}
                                                    AND `services`.`serviceID` = `checks_stat`.`serviceID`
                                                    AND `services`.`serviceNameID` = `service_names`.`serviceNameID`
                                                    AND `service_names`.`langID` = {$answer['lang_id']}
                                                    AND `checks_stat`.`actionDate`>='{$params->from}' 
                                                    AND `checks_stat`.`actionDate`<='{$params->to}'
                                                    GROUP BY `services`.`serviceParentID`) AS `parent` ON `serv_list`.`serviceID` = `parent`.`parentID`");
        break;
    case "by_user":
        $check_end = CLIENT_STOP;
        $service_module = 1;
        $user_list = $con->queryNoDML("SELECT `user`.`userID` AS 'user_id', `user`.`username` AS 'username', GROUP_CONCAT(`user_param_names`.`text`) AS 'labels', GROUP_CONCAT(`user_param_values`.`text`) AS 'values'
                                                FROM `user` JOIN `user_info` JOIN `user_param_names` JOIN `user_param_values` JOIN `user_access`
                                                WHERE `user`.`userID` = `user_info`.`userID`
                                                AND `user_info`.`userParamNameID` = `user_param_names`.`userParamNameID`
                                                AND `user_info`.`userParamValueID` = `user_param_values`.`userParamValueID`
                                                AND `user_param_names`.`langID` = {$answer['lang_id']}
                                                AND `user_access`.`userID` = `user`.`userID`
                                                AND `user_access`.`moduleID` = {$service_module}
                                                GROUP BY `user`.`userID`");
        $answer['info'] = [];
        for ($i = 0; $i < count($user_list); $i++) {
            $full_name_array = explode(",", $user_list[$i]["values"]);
            $full_name = $full_name_array[0] . " " . $full_name_array[count($full_name_array)-1];
            $cur_user = intval($user_list[$i]['user_id']);
            $answer['info'][count($answer['info'])] = ["user_id" => $user_list[$i]['user_id'], "username" => $user_list[$i]["username"], "user_name" => $full_name, "stat" => [], "sum_count" => 0, "sum_avg" => 0];
            $answer['info'][$i]["stat"] = $con->queryNoDML("SELECT * FROM
                                                                    (SELECT `branchID` AS 'branch_id',
                                                                    IFNULL(`serv_list`.`userID`, `parent`.`userID`) AS 'user_id',
                                                                    `serviceID` AS 'service_id',
                                                                    `serv_list`.`parentID` AS 'parent_id',
                                                                    `name` AS 'service_name',
                                                                    IFNULL(`serv_list`.`count`, `parent`.`count`) AS 'count',
                                                                    IFNULL(`serv_list`.`avgTime`, `parent`.`avgTime`) AS 'avg_time'
                                                                    FROM
                                                                    (SELECT `list`.`branchID` AS 'branchID', `service`.`userID` AS 'userID', `list`.`parentID` AS 'parentID', `list`.`serviceID` AS 'serviceID', `list`.`name` AS 'name', `service`.`count` AS 'count', `service`.`avgTime` AS 'avgTime' FROM
                                                                    (SELECT `branch_service`.`branchID` AS 'branchID', `services`.`serviceParentID` AS 'parentID', `services`.`serviceID` AS 'serviceID', `service_names`.`text` AS 'name'
                                                                    FROM `services` JOIN `branch_service` JOIN `service_names`
                                                                    WHERE `branch_service`.`serviceID` = `services`.`serviceID`
                                                                    AND `service_names`.`serviceNameID` = `services`.`serviceNameID`
                                                                    AND `service_names`.`langID` = {$answer['lang_id']}) AS `list`
                                                                    LEFT JOIN
                                                                    (SELECT `branch_user_window`.`userID` AS 'userID', `services`.`serviceID` AS 'serviceID', COUNT(`checks_stat`.`checkName`) AS 'count', AVG(`checks_stat`.`actionTime`-`checks_stat`.`prevTime`) AS 'avgTime'
                                                                    FROM `services` JOIN `service_names` JOIN `checks_stat` JOIN `branch_user_window`
                                                                    ON `checks_stat`.`checkStatusID` = {$check_end}
                                                                    AND `services`.`serviceID` = `checks_stat`.`serviceID`
                                                                    AND `services`.`serviceNameID` = `service_names`.`serviceNameID`
                                                                    AND `service_names`.`langID` = {$answer['lang_id']}
                                                                    AND `branch_user_window`.`windowID` = `checks_stat`.`windowID`
                                                                    AND `branch_user_window`.`userID` = {$cur_user}
                                                                    AND `checks_stat`.`actionDate`>='{$params->from}' 
                                                                    AND `checks_stat`.`actionDate`<='{$params->to}'
                                                                    GROUP BY `services`.`serviceID`) AS `service`
                                                                    ON `list`.`serviceID` = `service`.`serviceID`) AS `serv_list`
                                                                    LEFT JOIN
                                                                    (SELECT `branch_user_window`.`userID` AS 'userID', `services`.`serviceParentID` AS 'parentID', COUNT(`checks_stat`.`checkName`) AS 'count', AVG(`checks_stat`.`actionTime`-`checks_stat`.`prevTime`) AS 'avgTime'
                                                                    FROM `services` JOIN `service_names` JOIN `checks_stat` JOIN `branch_user_window`
                                                                    ON `checks_stat`.`checkStatusID` = {$check_end}
                                                                    AND `services`.`serviceID` = `checks_stat`.`serviceID`
                                                                    AND `services`.`serviceNameID` = `service_names`.`serviceNameID`
                                                                    AND `service_names`.`langID` = {$answer['lang_id']}
                                                                    AND `branch_user_window`.`windowID` = `checks_stat`.`windowID`
                                                                    AND `branch_user_window`.`userID` = {$cur_user}
                                                                    AND `checks_stat`.`actionDate`>='{$params->from}' 
                                                                    AND `checks_stat`.`actionDate`<='{$params->to}'
                                                                    GROUP BY `services`.`serviceParentID`) AS `parent`
                                                                    ON `serv_list`.`serviceID` = `parent`.`parentID`) AS `by_user` WHERE `user_id` IS NOT NULL");
            $one = 0;
            if ($answer['info'][$i]["stat"] == false) {
                $answer['info'][$i]["stat"] = [];
                $count = 0;
            }
            for ($j = 0; $j < count($answer['info'][$i]["stat"]); $j++) {
                $count = $j;
                if ($answer['info'][$i]["stat"][$j]["parent_id"] == 0 && $answer['info'][$i]["stat"][$j]["count"] != 0) {
                    $one = $answer['info'][$i]["stat"][$j]["avg_time"] / $answer['info'][$i]["stat"][$j]["count"];
                }
            }
            $answer['info'][$i]["sum_count"] = $count;
            $answer['info'][$i]["sum_avg"] = $one;
        }
        break;
    case "by_time":
        $check_end = CLIENT_STOP;
        $check_forwarded = CLIENT_FORWARD;
        $user_login = USER_LOGIN;
        $user_logout = USER_LOGOUT;
        $user_service = USER_FREE;
        $user_pause = USER_PAUSE;
        $service_module = 1;
        $user_list = $con->queryNoDML("SELECT `user`.`userID` AS 'user_id', `user`.`username` AS 'username', GROUP_CONCAT(`user_param_names`.`text`) AS 'labels', GROUP_CONCAT(`user_param_values`.`text`) AS 'values'
                                                FROM `user` JOIN `user_info` JOIN `user_param_names` JOIN `user_param_values` JOIN `user_access`
                                                WHERE `user`.`userID` = `user_info`.`userID`
                                                AND `user_info`.`userParamNameID` = `user_param_names`.`userParamNameID`
                                                AND `user_info`.`userParamValueID` = `user_param_values`.`userParamValueID`
                                                AND `user_param_names`.`langID` = {$answer['lang_id']}
                                                AND `user_access`.`userID` = `user`.`userID`
                                                AND `user_access`.`moduleID` = {$service_module}
                                                GROUP BY `user`.`userID`");
        $answer['info'] = [];
        for ($i = 0; $i < count($user_list); $i++) {
            $full_name_array = explode(",", $user_list[$i]["values"]);
            $full_name = $full_name_array[0] . " " . $full_name_array[count($full_name_array)-1];
            $cur_user = intval($user_list[$i]['user_id']);
            $answer['info'][count($answer['info'])] = ["user_id" => $user_list[$i]['user_id'], "username" => $user_list[$i]["username"], "user_name" => $full_name, "stat" => []];
            $answer['info'][$i]["stat"] = false;
            $stat1 = $con->queryNoDML("SELECT `userID`, `actionDate`, `statusID`, `actionTime`
                                        FROM `users_stat`
                                        WHERE `userID` = {$user_list[$i]['user_id']}
                                        AND (`statusID` = {$user_login} OR `statusID` = {$user_logout})
                                        AND `actionTime`>='{$params->from}'
                                        AND `actionTime`<='{$params->to}'
                                        ORDER BY `actionTime`");
            if (!empty($stat1)){
                $k = 0;
                $stat10 = [];
                for ($j = 0; $j < count($stat1); $j++) {
                    if ($stat1[$j]["statusID"] == 1) {
                        if (!isset($stat10[$k])) {
                            $stat10[$k] = ['start' => $stat1[$j]['actionTime'], 'end' => $stat1[$j]['actionTime']];
                        }
                    } elseif ($stat1[$j]["statusID"] == 7) {
                        $stat10[$k]['end'] = $stat1[$j]['actionTime'];
                        $k++;
                    }
                }
                $time = 0;
                for ($j = 0; $j < count($stat10); $j++) {
                    $time += strtotime($stat10[$j]['end']) - strtotime($stat10[$j]['start']);
                }
                $full_time = $time;
                $count_in_time = $con->queryNoDML("SELECT COUNT(`checks_stat`.`checkName`) AS 'count'
                                            FROM `checks_stat` JOIN `branch_user_window`
                                            WHERE `checks_stat`.`windowID` = `branch_user_window`.`windowID`
                                            AND `checks_stat`.`actionTime`>='{$params->from}'
                                            AND `checks_stat`.`actionTime`<='{$params->to}'
                                            AND `branch_user_window`.`userID` = {$user_list[$i]['user_id']}
                                            AND (`checks_stat`.`checkStatusID` = {$check_end} OR `checks_stat`.`checkStatusID` = {$check_forwarded})")[0]['count'];
                $in_work = $con->queryNoDML("SELECT SUM(`checks_stat`.`actionTime` - `checks_stat`.`prevTime`) AS 'count'
                                            FROM `checks_stat` JOIN `branch_user_window`
                                            WHERE `checks_stat`.`windowID` = `branch_user_window`.`windowID`
                                            AND `checks_stat`.`actionTime`>='{$params->from}'
                                            AND `checks_stat`.`actionTime`<='{$params->to}'
                                            AND `branch_user_window`.`userID` = {$user_list[$i]['user_id']}
                                            AND (`checks_stat`.`checkStatusID` = {$check_end} OR `checks_stat`.`checkStatusID` = {$check_forwarded})")[0]['count'];
                $out_service = $con->queryNoDML("SELECT SUM(`actionTime`-`prevTime`) AS 'not_service'
                                        FROM `users_stat`
                                        WHERE `userID` = {$user_list[$i]['user_id']}
                                        AND `statusID` = {$user_service}
                                        AND `actionTime`>='{$params->from}'
                                        AND `actionTime`<='{$params->to}'
                                        ORDER BY `actionTime`")[0]['not_service'];
                $day_count = $con->queryNoDML("SELECT COUNT(*) AS 'count' FROM 
                                                                            (SELECT COUNT(actionDate) 
                                                                            FROM `users_stat` 
                                                                            WHERE `userID` = {$user_list[$i]['user_id']}
                                                                            AND `statusID` = {$user_login}
                                                                            AND `users_stat`.`actionTime`>='{$params->from}'
                                                                            AND `users_stat`.`actionTime`<='{$params->to}'
                                                                            GROUP BY `actionDate`
                                                                            ORDER BY `actionTime`) `count`")[0]['count'];
                $in_service = $full_time - $out_service;
                $wait = $in_service - $in_work;

                // $answer['info'][$i]['stat']['avg_service'] = $in_work / $day_count;

                $answer['info'][$i]['stat']['avg_count'] = $count_in_time / $day_count;
                $answer['info'][$i]['stat']['avg_wait'] = $wait / $day_count;
                $answer['info'][$i]['stat']['avg_out'] = $out_service / $day_count;
                $answer['info'][$i]['stat']['day'] = $day_count;
            }else{
                $answer['info'][$i]['stat']['avg_count'] = 0;
                $answer['info'][$i]['stat']['avg_wait'] = 0;
                $answer['info'][$i]['stat']['avg_out'] = 0;
                $answer['info'][$i]['stat']['day'] = 0;               
            }
        }
        break;
    default:

}
if ($answer['error'] > 0) {
    $answer['error'] = getError($answer['error'], $income_data->lang_id);
}
echo json_encode($answer);
