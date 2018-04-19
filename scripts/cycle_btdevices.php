<?php

/*
 * @version 0.4 (06.09.2011 bug fixed)
 */

chdir(dirname(__FILE__) . '/../');

include_once("./config.php");
include_once("./lib/loader.php");
include_once("./lib/threads.php");

set_time_limit(0);

// connecting to database
$db = new mysql(DB_HOST, '', DB_USER, DB_PASSWORD, DB_NAME);

include_once("./load_settings.php");
include_once(DIR_MODULES . "control_modules/control_modules.class.php");

$ctl = new control_modules();

/*
if (!Defined('SETTINGS_BLUETOOTH_CYCLE') || SETTINGS_BLUETOOTH_CYCLE == 0)
   exit;
*/

$bt_devices = array();

//windows file
$devices_file = SERVER_ROOT . "/apps/bluetoothview/devices.txt";

//linux command
$bts_cmd = 'sudo hcitool scan | grep ":"';
$bts_cmd_le = 'timeout -s INT 30s hcitool lescan | grep ":"';


$first_run    = 1;
$skip_counter = 10;

$sql = "update btdevices set FOUND = 0";
SQLSelect($sql);

echo "Running bluetooth scanner\n";

while (1)
{
    
   setGlobal((str_replace('.php', '', basename(__FILE__))) . 'Run', time(), 1);
   
   $skip_counter++;
   if ($skip_counter >= 1)
   {
      $skip_counter = 0;
      $data = '';
      
      if (IsWindowsOS())
      {
         // windows scanner
         $isDelete = unlink($devices_file);
         exec(SERVER_ROOT . '/apps/bluetoothview/bluetoothview.exe /stab ' . $devices_file);
         
         if (file_exists($devices_file))
         {
            $data = (LoadFile($devices_file));
            sleep(5);
         }
      }
      else
      {
         //linux scanner
         $bt_scan_arr = array();
         $str=exec($bts_cmd, $bt_scan_arr);
         $str=exec($bts_cmd_le, $bt_scan_arr);
         $lines = array();
         $btScanArrayLength = count($bt_scan_arr);
         
         for ($i = 0; $i < $btScanArrayLength; $i++)
         {
            if (!$bt_scan_arr[$i]) {
             continue;
            }
            //echo $bt_scan_arr[$i].PHP_EOL;
            $bt_scan = trim($bt_scan_arr[$i]);
            //echo $bt_scan.PHP_EOL;
            $btaddr = substr ($bt_scan, 0, 17);
            $btname = trim(substr ($bt_scan, 17));
            $lines[]    = $i . "\t" . $btname . "\t" . $btaddr;
         }
         
         $data = implode("\n",$lines);
      }
      
      $last_scan = time();
      //print_r ($data);
      if ($data)
      {
         $data = str_replace(chr(0), '', $data);
         $data = str_replace("\r", '', $data);
         $lines = explode("\n", $data);
         $total = count($lines);
         
         for ($i = 0; $i < $total; $i++)
         {
            $fields = explode("\t", $lines[$i]);
            $title  = trim($fields[1]);
            $mac    = trim($fields[2]);
            $user   = array();
            
            if ($mac != '')
            {
               if (!$bt_devices[$mac])
               {
                  // && !$first_run
                  //new device found
                  echo date('Y/m/d H:i:s') . ' Device found: ' . $mac . PHP_EOL;
                  
                  $sqlQuery = "SELECT * 
                                 FROM btdevices 
                                WHERE MAC LIKE '" . $mac . "'";
                  
                  $rec = SQLSelectOne($sqlQuery);
                  $previous_found = $rec['LAST_FOUND'];
                  $rec['FOUND'] = 1;
                  $rec['LAST_FOUND'] = date('Y/m/d H:i:s');
                  $rec['LOG'] = 'Device found ' . date('Y/m/d H:i:s') . "\n" . $rec['LOG'];
                  
                  if (!$rec['ID'] && $title != '(unknown)')
                  {
                     $rec['FIRST_FOUND'] = $rec['LAST_FOUND'];
                     $previous_found = $rec['LAST_FOUND'];
                     $rec['MAC'] = strtolower($mac);
                     
                     if ($title != '')
                        $rec['TITLE'] = 'Устройство: ' . $title;
                     else
                        $rec['TITLE'] = 'Новое устройство';
                     
                     $new = 1;
                     
                     $rec['ID']=SQLInsert('btdevices', $rec);
                  }
                  else
                  {
                     $new = 0;
                     
                     if ($rec['USER_ID'])
                     {
                        $sqlQuery = "SELECT * 
                                       FROM users 
                                      WHERE ID = '" . $rec['USER_ID'] . "'";
                        
                        $user = SQLSelectOne($sqlQuery);
                     }
                     
                     SQLUpdate('btdevices', $rec);
                  }
                  if ($rec['ID'])
                  {
                      $objectArray = array('mac'            => $mac,
                                           'user'           => $user['NAME'],
                                           'new'            => $new,
                                           'previous_found' => $previous_found,
                                           'last_found'     => $rec['FIRST_FOUND']);
                      
                      $obj=getObject('BlueDev');
                      if (is_object($obj)) {
                       $obj->raiseEvent("Found", $objectArray);
                      }
                  }
               }
               else
               {
                  $sqlQuery = "SELECT * 
                                 FROM btdevices 
                                WHERE MAC = '" . $mac . "'";
                  
                  $rec = SQLSelectOne($sqlQuery);
                  $rec['LAST_FOUND'] = date('Y/m/d H:i:s');
                  $rec['FOUND'] = 1;
                  
                  if ($title != '' && $title != '(unknown)')
                  {
                     $rec['TITLE'] = 'Устройство: ' . $title;
                  }
                  
                  if ($rec['ID'])
                     SQLUpdate('btdevices', $rec);
               }
               
               $bt_devices[$mac] = $last_scan;
            }
         }
      }
         foreach ($bt_devices as $k => $v)
         {
            if ($last_scan - $v >= 5*60)
            {
               //device removed
               echo date('Y/m/d H:i:s') . ' Device gone: ' . $k . PHP_EOL;
               
               $user = array();
               $sqlQuery = "SELECT * 
                              FROM btdevices 
                             WHERE MAC = '" . $k . "'";
               
               $rec  = SQLSelectOne($sqlQuery);
               
               if ($rec['ID'])
               {
                  $rec['LOG'] = 'Device lost ' . date('Y/m/d H:i:s') . "\n" . $rec['LOG'];
                  $rec['FOUND'] = 0;
                  SQLUpdate('btdevices', $rec);
                  
                  if ($rec['USER_ID'])
                  {
                     $sqlQuery = "SELECT * 
                                    FROM users 
                                   WHERE ID = '" . $rec['USER_ID'] . "'";
                     
                     $user = SQLSelectOne($sqlQuery);
                  }
               }
               
               $objectArray = array('mac'  => $k,
                                    'user' => $user['NAME']);
               

               $obj=getObject('BlueDev');
               if (is_object($obj)) {
                   $obj->raiseEvent("Lost", $objectArray);
               }
               unset($bt_devices[$k]);
            }
         }
   }

   $first_run = 0;
   
   if (file_exists('./reboot') || IsSet($_GET['onetime']))
   {
      $db->Disconnect();
      exit;
   }
   
   sleep(10);
}

// closing database connection
$db->Disconnect();

