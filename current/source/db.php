<?php
//require("auth.php");
ini_set("max_execution_time", "3600"); // 1 houre limit for scaning large folders!!
//date_default_timezone_set('GMT');
    $dbname         = "nzb"; 
	$dbhost         = "localhost";
    $dbuser         = "root";
$dbpass = getenv('NZB_DB_PASSWORD') ?: '';
	$footer         = "  <tr class=main1 id=\"ignore\">\n   <td colspan=3><a href=\"http://www.arden.ie/nzb\" target=\"_blank\">www.arden.ie/nzb</a> - <a href=\"index.php\">Home</a> - <a href=\"par2.php\">PAR2</a> - <a href=\"unpacking.php\">Unpacking</a> - <a href=\"renaming.php\">Renaming</a> - <a href=\"merging.php\">Merging</a> - <a href=\"moving.php\">Moving</a></td>\n  </tr>\n";	
	
    $dblink         = mysql_connect($dbhost, $dbuser, $dbpass);

	if(!$dblink)
		include("die.php");	
	else
		define('DBLINK', $dblink);

    $dbselected     = mysql_select_db($dbname, $dblink); 		
	$msg		    = "";

	if(isset($_GET["msg"]))
		$msg = $_GET["msg"];
				
	if(!$dbselected) 
	{     		
		if(!mysql_query('CREATE DATABASE nzb;', $dblink))   
			echo 'Error creating database: '.mysql_error()."\n";   
		else
			mysql_select_db($dbname, $dblink);
	} 

		
//----------------------------------------------------------------------------
function GetFilesArray($dir, $type)
{
	$files = array();
	
	if ($handle = opendir($dir)) 
	{
		while(false !== ($entry = readdir($handle))) 
		{
			if($entry != "." && $entry != "..") 
			{			
				if(strtolower(pathinfo($dir.$entry, PATHINFO_EXTENSION))==$type)
					$files[] = array(urlencode(substr($entry, 0, strlen($entry)-(strlen($type)+1))), $dir, $entry, filesize($dir.$entry), date("Y-m-d H:i:s", fileatime($dir.$entry)));
			}
		}
		
		closedir($handle);
	}
	
	return $files;
}
//----------------------------------------------------------------------------
function MakeNZBFileFromDB($h)
{
	$file = "<?xml version=\"1.0\" encoding=\"iso-8859-1\" ?>\n"
		   ."<!DOCTYPE nzb PUBLIC \"-//newzBin//DTD NZB 1.1//EN\" \"http://www.newzbin2.es/DTD/nzb/nzb-1.1.dtd\">\n"
		   ."<nzb xmlns=\"http://www.newzbin2.es/DTD/2003/nzb\">\n"; 
		   
	$result = mysql_query("SELECT * FROM hashes WHERE hash='".$h."';", DBLINK);	
	$h_id = mysql_fetch_row($result);	
	
	if(isset($h_id[0]))
	{	
		$result = mysql_query("SELECT * FROM meta WHERE hashid=".$h_id[0].";", DBLINK);		
			
		if(mysql_num_rows($result)>0)
		{		
			$file .= " <head>\n";
				
			while($h_data = mysql_fetch_row($result))
				$file .= "  <meta type=\"".str_replace('&#039;', '&apos;', htmlspecialchars($h_data[2], ENT_QUOTES))."\">".str_replace('&#039;', '&apos;', htmlspecialchars($h_data[3], ENT_QUOTES))."</meta>\n";									
				
			$file .= " </head>\n";
		}
							
		$result = mysql_query("SELECT * FROM files WHERE hashid=".$h_id[0]." ORDER BY subject;", DBLINK);			
				
		while($f_data = mysql_fetch_row($result))
		{		
			$file .= " <file poster=\"".str_replace('&#039;', '&apos;', htmlspecialchars($f_data[2], ENT_QUOTES))."\" date=\"".$f_data[3]."\" subject=\"".str_replace('&#039;', '&apos;', htmlspecialchars($f_data[4], ENT_QUOTES))."\">\n";
			$file .= "  <groups>\n";
			$g_array = array();
			$g_array = explode(",", $f_data[5]);
			
			foreach($g_array as $val)
				$file .= "   <group>$val</group>\n";
				
			$file .= "  </groups>\n";
			$result2 = mysql_query("SELECT * FROM segments WHERE fileid=".$f_data[0]." ORDER BY number;", DBLINK);	
			$file .= "  <segments>\n";
				
			while($s_data = mysql_fetch_row($result2))
				$file .= "   <segment bytes=\"".$s_data[3]."\" number=\"".$s_data[4]."\">".str_replace('&#039;', '&apos;', htmlspecialchars($s_data[5], ENT_QUOTES))."</segment>\n";
				
			$file .= "  </segments>\n";			
			$file .= " </file>\n";	
		}
		
		$file .= "</nzb>\n";	
	}
	
	return $file;
}
//----------------------------------------------------------------------------
function contains($substring, $string) 
{
    $pos = stripos($string, $substring);
 
    if($pos === false)// string needle NOT found in haystack
        return false;
    else  // string needle found in haystack
        return true; 
}
//----------------------------------------------------------------------------
?>