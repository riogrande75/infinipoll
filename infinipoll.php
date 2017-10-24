#!/usr/bin/php
<?php
//Allg. Einstellungen
//$moxa_ip = "udp://192.168.1.65"; //USR_TCP232_UDP_Mode
$moxa_ip = "192.168.1.65"; //USR_TCP232_TCP_Server
$moxa_port = 20108;
$moxa_timeout = 10;
$warte_bis_naechster_durchlauf = 2; //Zeit zw. zwei Abfragen in Sekunden
$tmp_dir = "/tmp/";             //Speicherort/-ordner fuer akt. Werte -> am Ende ein / !!!
$error = [];
$schleifenzaehler = 0;

//Logging/Debugging Einstellungen:
$debug = false;         //Debugausgaben und Debuglogfile
$log2console = false;
$fp_log = false;
$script_name = "infinipoll.php";
$logfilename = "/etc/infinipoll/log/infinipoll_";     //Debugging Logfile

//Initialisieren der Variablen:
$is_error_write = false;
$totalcounter = 0;
$daybase = 0;
$daybase_yday = 0;

//Syslog oeffnen
openlog($script_name, LOG_PID | LOG_PERROR, LOG_LOCAL0);
syslog(LOG_ALERT,"INFINIPOLL Neustart");

// Get model,version and protocolID for infini_startup.php
$fp = @fsockopen($moxa_ip, $moxa_port, $errno, $errstr, $moxa_timeout);
fwrite($fp, "QMD".chr(0x0d)); //Device Model Inquiry 48byte
$byte=fgets($fp,51);
if(dechex(ord(substr($byte,0,1)))!="28")
{
	if($debug) logging("EMPFANGSPROBLEM beim START!!!");
	exit;
}
$model=substr($byte,1,46);
fwrite($fp, "QVFW".chr(0x0d)); //Device Model Inquiry 16Byte
$byte = fgets($fp,19);
$version = substr($byte,1,14);
fwrite($fp, "QID".chr(0x0d)); //QID Inverter ID abfragen 18byte
$byte=fgets($fp,19);
$devid = substr($byte,1,15);
fwrite($fp, "QPI".chr(0x0d)); //QPI Protocol ID abfragen 6byte
$byte=fgets($fp,9);
$protid = substr($byte,1,4);
fwrite($fp, "I".chr(0x0d)); //I Info bzgl. MCU und DSP 13byte
$byte=fgets($fp,42);
$dspver = substr($byte,1,18);
$mcuver =  substr($byte,20,18);
$CMD_INFO = "echo \"INFINI Solar\n$model\nSerial:$devid\nSW:$version\nProtokoll:$protid\n$mcuver\n$dspver\"";

write2file_string($tmp_dir."iINFO.txt",$CMD_INFO);

//get date+time and set current time from server
fwrite($fp, "QT".chr(0x0d)); //QT Time inquiry 16byte
$byte=fgets($fp,19);
if($debug) logging("aktuelle Zeit im WR: ".substr($byte,1,14));
$datum = date("ymd");
$zeit = date("His");
fwrite($fp,"DAT".$datum.$zeit.chr(0x0d)); //QT Time inquiry 16byte
$byte=fgets($fp,8);
if(substr($byte,1,3)=="ACK")
	{
	if($debug) logging("Uhrzeit gesetzt");}
	else {
	if($debug) logging("Fehler beim Uhrzeit setzen!");
	}

//Ermitteln eines Anfangswertes fuer ipv_ges:
$daybase = file_get_contents($tmp_dir."ipv_ges_yday.txt");
if($daybase==0)
	{
	if($debug) logging("Tageszähler war 0 - wird neu vom WR geholt");
	//Get total-counter
	fwrite($fp, "QET".chr(0x0d)); //QET Inquiry total energy 13byte
	$byte=fgets($fp,13);
	$totalcounter = substr($byte,1,8); // in KWh
	$month=date("m");
	$year=date("Y");
	$day=date("d");
	$check = cal_crc_half("QED".$year.$month.$day);
	fwrite($fp, "QED".$year.$month.$day.$check.chr(0x0d)); //QED Inquiry total energy in day
	$byte=fgets($fp,11);
	$daypower = substr($byte,1,6); // in Wh
	$daytemp = $daypower/1000 - ((int)($daypower/1000));
	$daybase = ($totalcounter - ($daytemp/1000));
	if($debug) logging("TotalCounter-IntDaycounter: ".$daybase." KWh");
        $pv_ges = ($daybase+($daypower/1000)); // in KWh
	fclose($fp);
}
// Hauptschleife
while(true)
{
	$err = false;
	$schleifenzaehler++;
	if($schleifenzaehler==100) // Abfrage ca.alle 5 Minuten
		{
		getalarms();
		$schleifenzaehler=0;
		continue;
		}
	if($debug && $fp_log) @fclose($fp_log);
        if($debug) $fp_log = @fopen($logfilename.date("Y-M-d").".log", "a");    //schreibe ein File pro Tag!
        $err = false;

        //Aufbau der Verbindung zum Serial2ETH Wandler
        $fp = @fsockopen($moxa_ip, $moxa_port, $errno, $errstr, $moxa_timeout);

        if (!$fp)
        {
                logging("Fehler beim Verbindungsaufbau: $errstr ($errno)");
                sleep($warte_bis_naechster_durchlauf);
                continue;
        }
        // Wenn genau 23 Uhr Abends, Totalcounter wird als daybase_yesterday gespeichert
        if(date("H")=="23")
                {
                if($debug) logging("23 Uhr Abends, Tageszähler wird gespeichert");
                $daybase_yday = file_get_contents($tmp_dir."ipv_ges.txt");
                if($debug) logging("DAYBASE_YESTERDAY: ".$daybase_yday." KWh");
		write2file($tmp_dir."ipv_ges_yday.txt",$daybase_yday);
                }
        // Wenn genau 1 Uhr Morgens, Totalcounter wird von ipv_ges_yday als TagesBasis geholt:
        if(date("H")=="01")
                {
                if($debug) logging("Ein Uhr Morgens, Tageszähler wird neu gesetzt");
		$daybase = file_get_contents($tmp_dir."ipv_ges_yday.txt");
                if($debug) logging("DAYBASE-Counter: ".$daybase." KWh");
                }
	//Abfrage des InverterModus
	fwrite($fp, "QMOD".chr(0x0d)); ////QMOD Device Mode inquiry 3byte
	$byte=fgets($fp,6);
	if(dechex(ord(substr($byte,0,1)))!="28")
		{
		if($debug) logging("EMPFANGSPROBLEM beim MODUS lesen");
		sleep($warte_bis_naechster_durchlauf);
                continue;
		}
	$modus = substr($byte,1,1);
	switch ($modus) {
    		case "P":
        	$modusT="PowerOn";
        	break;
    		case "S":
        	$modusT="StandBy";
        	break;
    		case "Y":
        	$modusT="Bypass";
        	break;
    		case "L":
        	$modusT="Line";
       		break;
    		case "B":
        	$modusT="Battery";
        	break;
    		case "T":
        	$modusT="BatteryTest";
        	break;
    		case "F":
        	$modusT="Fault";
        	break;
    		case "D":
        	$modusT="Shutdown";
        	break;
    		case "G":
        	$modusT="Grid";
        	break;
    		case "C":
        	$modusT="Charge";
        	break;
    		default:
         	$modusT="unknown";
		continue;
	}
	if($modus=="S" || $modus=="Y" || $modus=="T" || $modus=="D")
		{
		if($debug) logging("=====>>>>WR ist im ".$modusT."-Modus, daher sind Abfragen verboten!");
		batterie_nacht();
		sleep(60); //Warte 1 Minute, weil Nachts eh nicht viel passiert
        	continue;
		}
	if($modus=="F") // WR im im Fehlermodus
		{
		fclose($fp);
		getalarms();
		if($debug) logging("WR im FAULT-STATUS!!! Fehler siehe iALARM.txt!");
		sleep(60); //Warte 1 Minuten weil Nachts eh nix passiert
		continue;
		}
	if($debug) logging("================================================");
        if($debug) logging("Modus: ".$modusT);

        //HAUPTABFRAGE sende Request fuer Mess-Daten, siehe "RS232 Protocol.pdf"
	fwrite($fp, "QPIGS".chr(0x0d)); //QPIGS Device general status parameters inquiry 135 --desises
	$byte=fgets($fp,137); //137
	if($byte === FALSE)     //Fehler beim Empfang -> z.B. Verbindung abgebrochen!
	{
		logging("Fehler: beim Empfang von Byte $index: ".bin2hex($byte));
		$err = true;
	}
	// Startbyte pruefen
        if(dechex(ord(substr($byte,0,1)))!="28")
                {
                if($debug) logging("EMPFANGSPROBLEM beim QPIGS lesen: startbyte");
		$err = true;
                }
	// Netzspannung auswerten + pruefen
	$gridvolt = substr($byte,1,5);
	if($gridvolt < 180 || $gridvolt > 250)
		{
		if($debug) logging("EMPFANGSPROBLEM beim QPIGS lesen: gridvolt");
                $err = true;
		}
	// Grip Power auswertern + pruefen
	$gridpower = substr($byte,6,7);
        if($gridpower > 3500)
		{
                if($debug) logging("EMPFANGSPROBLEM beim QPIGS lesen: gridpower");
		$err = true;
		}

	$gridfreq = substr($byte,13,5);
//	$gridamps = substr($byte,18,7); //da kommt kein brauchbarer Wert-> Strom wird errechnet
	if($gridvolt > 180)
		{
		$gridamps = $gridpower / $gridvolt;
		}
		else  $gridamps = 0;
	$loadvolt = substr($byte,26,5);
	$loadpower = substr($byte,32,5);
	$loadfreq = substr($byte,37,5);
	$loadamps = substr($byte,43,5);
	$loadperc = substr($byte,48,4);

	// Batteriespannung auswerten + pruefen
	$battvolt = substr($byte,65,5);
        if($battvolt < 1 || $battvolt > 80)
                {
		if($debug) logging("EMPFANGSPROBLEM beim QPIGS lesen: battvolt");
		$err = true;
		}
        // Batteriekapazitaet auswerten + pruefen
	$battcap = substr($byte,77,3);
        if($battcap < 1 || $battcap > 100)
                {
		if($debug) logging("EMPFANGSPROBLEM beim QPIGS lesen: battcap");
                $err = true;
		}
        // PV-Leistung auswerten + pruefen
	$pvpower = substr($byte,80,6);
        if($pvpower > 5000)
		{
                if($debug) logging("EMPFANGSPROBLEM beim QPIGS lesen: pvpower");
		$err = true;
		}
	// PV-Spannung auswerten + pruefen
	$pvvolt = substr($byte,98,6);
        if($pvvolt < 110) // Startspannung 116V lt. Datenblatt
                {
                if($debug) logging("WR noch nicht bereit: PV Spannung ist nur $pvvolt Volt, warte 1 Min.");
		batterie_nacht();
                sleep(60); //Warte 3 Minuten weil Nachts eh nix passiert
//		fclose($fp);
		continue;
                }

	$temp = substr($byte,116,6);
	$battcode =  substr($byte,123,1);
	$battstat="illegal";
	if (substr($byte,127,2)=="00") $battstat="Do nothing";
	if (substr($byte,127,2)=="01") $battstat="Charging";
	if (substr($byte,127,2)=="10") $battstat="Discharging";

	$tempbatt1 = substr($byte,123,10);
	$tempbatthex1 = bin2hex(substr($byte,133,2));
	$tempbatt = $tempbatt1 . "+x ".$tempbatthex1;
	if($debug) logging("DEBUG_BATT: ".$tempbatt);

	fwrite($fp, "QCHGS".chr(0x0d)); //Charger status inquiry
	$byte=fgets($fp,24);
	// Get values from reply
	// Battery Charging Current
	//echo "QCHGS-Empfang:".substr($byte, 0, -3)."\n";
	$battchamp = substr($byte,1,4);

        //Get day-counter
        $month=date("m");
        $year=date("Y");
        $day=date("d");
        $check = cal_crc_half("QED".$year.$month.$day);
        fwrite($fp, "QED".$year.$month.$day.$check.chr(0x0d)); //QEM Inquiry total energy in day
        $byte=fgets($fp,11);
        $daypower = substr($byte,1,6);
        if($debug) logging("ToDayPower: ".$daypower." Wh");

	if($err)
        {
                fclose($fp);
                sleep($warte_bis_naechster_durchlauf);
                continue;
        }

        //Ergebnisse auswerten und in Dezimal umwandeln:
	$pv_ges = ($daybase+($daypower/1000)); // in KWh
        if($debug) logging("DEBUG: pv_ges: ".$pv_ges);
	$akt_power = $gridpower;
        $ACV   = $gridvolt;
        $ACC  = round($gridamps,6);
        $ACF   = $gridfreq;
        $INTEMP= $temp;
        $DCINV = $pvvolt;
        $DCINC = round(($pvpower/$pvvolt),3);
        $INPA  = $pvpower;

        if($debug) logging("DEBUG Wert ACV: $ACV");
        if($debug) logging("DEBUG Wert ACC: $ACC");
        if($debug) logging("DEBUG Wert ACF: $ACF");
        if($debug) logging("DEBUG Wert INTEMP: $INTEMP");
        if($debug) logging("DEBUG Wert DCINV: $DCINV");
        if($debug) logging("DEBUG Wert DCINC: $DCINC");
        if($debug) logging("DEBUG Wert INPA: $INPA");
	if($debug) logging("DEBUG Wert BATTV: $battvolt");
        if($debug) logging("DEBUG Wert BATTCHAMP: $battchamp");
	if($debug) logging("DEBUG Wert BATTCAP: $battcap");
        if($debug) logging("DEBUG: ges. PV in KWh: $pv_ges");
        if($debug) logging("DEBUG: akt. Leistung in Watt: $akt_power");
	if($debug) logging("DEBUG: Batterie Status: $battcode");

        //schreibe akt. Daten in Files, die wiederum von 123solar drei Mal pro Sek. abgefragt werden:
	$ts = time();   //akt. Timestamp abfragen!
	write2file($tmp_dir."ipv_ges.txt",$pv_ges);    //umwandlen in kWh!
        write2file($tmp_dir."iACV.txt",$ACV);
        write2file($tmp_dir."iACC.txt",$ACC);
        write2file($tmp_dir."iACF.txt",$ACF);
        write2file($tmp_dir."iINTEMP.txt",$INTEMP);
        write2file($tmp_dir."iDCINV.txt",$DCINV);
        write2file($tmp_dir."iDCINC.txt",$DCINC);
        write2file($tmp_dir."iINPA.txt",$INPA);
        write2file($tmp_dir."iakt_power.txt",$akt_power);
	write2file($tmp_dir."iBATTV.txt",$battvolt);
	write2file($tmp_dir."iBATTCAP.txt",$battcap);
	write2file($tmp_dir."iBATTCHAMP.txt",$battchamp);
	write2file_string($tmp_dir."iSTATE.txt",$modusT);
	write2file_string($tmp_dir."iBATTSTAT.txt",$battstat);
	write2file_string($tmp_dir."iBATTCODE.txt",$battcode);
	write2file_string($tmp_dir."its.txt",date("Ymd-H:i:s",$ts));
	//Verbindung zu WR abbauen
        fclose($fp);
	// Warte bis naechster Durchlauf
        sleep($warte_bis_naechster_durchlauf);
}

// Div. Funtionen zur Datenaufbereitung
function hex2str($hex) {
    $str = '';
    for($i=0;$i<strlen($hex);$i+=2) $str .= chr(hexdec(substr($hex,$i,2)));
    return $str;
}
function cal_crc_half($pin)
        {
        $sum = 0;
        for($i = 0; $i < strlen($pin); $i++)
                {
                $sum += ord($pin[$i]);
                }
        $sum = $sum % 256;
        if(strlen($sum)==2) $sum="0".$sum;
        if(strlen($sum)==1) $sum="00".$sum;
        return $sum;
}
function write2file($filename, $value)
{
        global $is_error_write, $log2console;
        $fp2 = fopen($filename,"w");
        if(!$fp2 || !fwrite($fp2, (float) $value))
        {
                if(!$is_error_write)
                {
                        logging("Fehler beim Schreiben in die Datei $filename Start (weitere Meldungen werden unterdrueckt)!", true);
                }
                $is_error_write = true;
        }
        else if($is_error_write)
        {
                logging("Fehler beim Schreiben bereinigt!", true);
                $is_error_write = false;
        }
        fclose($fp2);
}
function write2file_string($filename, $value)
{
	global $is_error_write, $log2console;
	$fp2 = fopen($filename,"w");
	if(!$fp2 || !fwrite($fp2, $value))
	{
		if(!$is_error_write)
		{
			logging("Fehler beim Schreiben in die Datei $filename Start (weitere Meldungen werden unterdrueckt)!", true);
                }
                $is_error_write = true;
        }
        else if($is_error_write)
        {
                logging("Fehler beim Schreiben bereinigt!", true);
                $is_error_write = false;
        }
        fclose($fp2);
}
function logging($txt, $write2syslog=false)
{
        global $fp_log, $log2console, $debug;
        if($log2console) echo date("Y-m-d H:i:s").": $txt<br />\n";
        if($debug)
                {
                fwrite($fp_log, date("Y-m-d H:i:s").": $txt<br />\n");
                if($write2syslog) syslog(LOG_ALERT,$txt);
                }
}
function batterie_nacht() {
// Nachts NUR die Werte der Batterie abfragen
global $debug, $err, $fp, $tmp_dir, $warte_bis_naechster_durchlauf;
fwrite($fp, "QPIGS".chr(0x0d)); //QPIGS Device general status parameters inquiry 137
	$byte=fgets($fp,137); //137
	if($byte === FALSE)     //Fehler beim Empfang -> z.B. Verbindung abgebrochen!
	{
        	logging("Nacht-Fehler: beim Empfang von Byte $index: ".bin2hex($byte));
        	$err = true;
	}
	// Startbyte pruefen
	if(dechex(ord(substr($byte,0,1)))!="28")
        	{
        	if($debug) logging("Nacht-EMPFANGSPROBLEM beim QPIGS lesen: startbyte");
        	$err = true;
        	}
	// Batteriespannung auswerten + pruefen
	$battvolt = substr($byte,65,5);
	if($battvolt < 1 || $battvolt > 80)
        	{
        	if($debug) logging("Nacht-EMPFANGSPROBLEM beim QPIGS lesen: battvolt");
        	$err = true;
        	}
	// Batteriekapazitaet auswerten + pruefen
	$battcap = substr($byte,77,3);
	if($battcap < 1 || $battcap > 100)
        	{
        	if($debug) logging("Nacht-EMPFANGSPROBLEM beim QPIGS lesen: battcap");
        	$err = true;
        	}
//	$temp = substr($byte,116,6);
	$battcode =  substr($byte,123,1);
	$battstat="illegal";
	if (substr($byte,127,2)=="00") $battstat="Do nothing";
	if (substr($byte,127,2)=="01") $battstat="Charging";
	if (substr($byte,127,2)=="10") $battstat="Discharging";

        $tempbatt1 = substr($byte,123,10);
        $tempbatthex1 = bin2hex(substr($byte,133,2));
        $tempbatt = $tempbatt1 . "+x ".$tempbatthex1;
        if($debug) logging("DEBUG_BATT: ".$tempbatt);

        if($err)
        {
                fclose($fp);
                sleep($warte_bis_naechster_durchlauf);
                continue;
        }
fwrite($fp, "QCHGS".chr(0x0d)); //Charger status inquiry
        $byte=fgets($fp,24);
        // Get values from reply
        // Battery Charging Current
        $battchamp = substr($byte,2,4);

	// Werte in die Files schreiben
	write2file($tmp_dir."iBATTV.txt",$battvolt);
	write2file($tmp_dir."iBATTCAP.txt",$battcap);
	write2file_string($tmp_dir."iBATTSTAT.txt",$battstat);
	write2file_string($tmp_dir."iBATTCODE.txt",$battcode);
        write2file($tmp_dir."iBATTCHAMP.txt",$battchamp);
        if($debug) logging("DEBUG Wert BATTV: $battvolt");
        if($debug) logging("DEBUG Wert BATTCHAMP: $battchamp");
        if($debug) logging("DEBUG Wert BATTCAP: $battcap");
   fclose($fp);
}
function getalarms() {
// Alarme Auslesen aus dem WR alle paar Minuten
$moxa_ip = "udp://192.168.1.62";
$moxa_port = 20108;
$moxa_timeout = 10;
$debug = false;
$fp = @fsockopen($moxa_ip, $moxa_port, $errno, $errstr, $moxa_timeout);
// Read fault register
// Infini
// Command: QPIWS - Device Warning Status inquiry
//                     111111111122222222223
//           0123456789012345678901234567890
// Answer:      (--0000000000--00110---000000----------------------------------------------------------------------------------------------------*
fwrite($fp, "QPIWS".chr(0x0d)); //Device Warning Status inquiry
$byte=fgets($fp,133);

for($i = 1; $i < 26; $i++){
        $fehlerbit = substr($byte,$i,1);
        if($debug) echo "fehlerbit ".$i." ist ".$fehlerbit."\n";
        if($fehlerbit != "0" && $fehlerbit != "1" && $fehlerbit != "-") echo "Fehler beim Emfang: Bit ".$i." = ".$fehlerbit."\n";
        //Bituebersetzungstabelle:
        if($i==1 && $fehlerbit=="1") $error[$i] = "PV fail\n";
        if($i==2 && $fehlerbit=="1") $error[$i] = "Auto adjust processing\n";
        if($i==3 && $fehlerbit=="1") $error[$i] = "External flash fail\n";
        if($i==4 && $fehlerbit=="1") $error[$i] = "PV loss\n";
        if($i==5 && $fehlerbit=="1") $error[$i] = "PV low\n";
        if($i==6 && $fehlerbit=="1") $error[$i] = "Islanding detect\n";
        if($i==7 && $fehlerbit=="1") $error[$i] = "Initial fail\n";
        if($i==8 && $fehlerbit=="1") $error[$i] = "Grid voltage high loss\n";
        if($i==9 && $fehlerbit=="1") $error[$i] = "Grid voltage low loss\n";
        if($i==10 && $fehlerbit=="1") $error[$i] = "Grid frequency high loss\n";
        if($i==11 && $fehlerbit=="1") $error[$i] = "Grid frequency low loss\n";
        if($i==12 && $fehlerbit=="1") $error[$i] = "Feeding average voltage over\n";
        if($i==13 && $fehlerbit=="1") $error[$i] = "get energy from the grid\n";
        if($i==14 && $fehlerbit=="1") $error[$i] = "Grid fault\n";
        if($i==15 && $fehlerbit=="1") $error[$i] = "Battery under\n";
        if($i==16 && $fehlerbit=="1") $error[$i] = "Battery low\n";
        if($i==17 && $fehlerbit=="1") $error[$i] = "Battery open\n";
        if($i==18 && $fehlerbit=="1") $error[$i] = "Battery discharge low\n";
        if($i==19 && $fehlerbit=="1") $error[$i] = "Over load\n";
        if($i==20 && $fehlerbit=="1") $error[$i] = "EPO active EPO activate\n";
        if($i==21 && $fehlerbit=="1") $error[$i] = "PV1 loss\n";
        if($i==22 && $fehlerbit=="1") $error[$i] = "PV2 loss\n";
        if($i==23 && $fehlerbit=="1") $error[$i] = "Over temperature\n";
        if($i==24 && $fehlerbit=="1") $error[$i] = "Ground loss\n";
        if($i==25 && $fehlerbit=="1") $error[$i] = "Fan Lock\n";
}
if ($debug) for($i = 1; $i < 26; $i++){
        if(isset($error[$i])) echo $error[$i];
        }
$fp = fopen('/tmp/iALARM.txt',"w");
if($fp)
        {
        for($i = 1; $i < 26; $i++)
                {
                if(isset($error[$i]))
                        {
                        fwrite($fp, $error[$i]);
                        }
                }
        }
fclose($fp);
}
?>
