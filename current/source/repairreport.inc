<?php
//--------------------------------------------------------------------------------
function GetUpdate($f)
{
	$d       = @file_get_contents($f);
	$order   = array("\r\n", "\n", "\r");
	$replace = "<BR>";
	$newstr  = str_replace($order, $replace, $d);
	
	$da = explode($replace, $newstr);
	$da = array_filter($da);// remove empty elements

	//foreach ($da as $key=>$value) // remove empty lines
	//	if(empty($value))
	//		unset($da[$key]);

	return array_pop($da); // return last line
}
//--------------------------------------------------------------------------------
function GetError($f)
{
	$d    = @file_get_contents($f);	
	$order   = array("\r\n", "\n", "\r");
	$replace = "<BR>";
	$newstr = str_replace($order, $replace, $d);

	$da   = explode("<BR>", $newstr);	
	$data = "";
		
	foreach($da as $key=>$value)
		//if(empty($value))
		//	unset($da[$key]);
		//else
			$data.=$value."<BR>";

	return $data;
}
//--------------------------------------------------------------------------------
function GetFileSegTotalSize($fileid)
{
	$ret = 0;
	
	$result = mysql_query("SELECT SUM(bytes) FROM segments WHERE fileid=$fileid;", DBLINK);
	$value  = mysql_fetch_row($result);
	$ret    = $value[0];
	
	return $ret;
}

//--------------------------------------------------------------------------------
function strcontains($substring, $string) 
{
    $pos = stripos($string, $substring);
 
    if($pos === false)// string needle NOT found in haystack
        return false;
    else  // string needle found in haystack
        return true; 
}
//----------------------------------------------------------------------------
function StripName($name)
{
	$nname = $name;

	$nname = substr($name, (strpos($name, "\"")+1));
	$nname = substr($nname, 0, strrpos($nname, "\""));		
	
	return $nname;	
}
//--------------------------------------------------------------------------------
function LoadMorePar2Files($File)
{
	$fileparts = pathinfo($File);
	$ret       = "";		
	$result    = mysql_query("SELECT hashid, subject, id FROM files WHERE subject LIKE '%".$fileparts['filename']."%';", DBLINK);	

	if(@mysql_num_rows($result)>0)
	{
		while($value = mysql_fetch_row($result))
		{
			$data[$value[2]] = StripName($value[1]);
			$data['hashid']  = $value[0];
			$tmpfile         = pathinfo(StripName($value[1]));
		}

		$result2  = mysql_query("SELECT subject, id FROM files WHERE subject LIKE '%".$tmpfile['filename'].".vol%' AND hashid=".$data['hashid']." ORDER BY subject;", DBLINK);			
	
		if(@mysql_num_rows($result2)>0)
		{
			while($value = mysql_fetch_row($result2))
				$data[$value[1]] = StripName($value[0]);
		}
				
		$ret = DisplayPars($data);		
	}
	
	unlink($File);
	
	return $ret;
}
//----------------------------------------------------------------------------
function DisplayPars($Files)
{
	global $nzb;
	$num = 1;
	
	if(count($Files)>0)
	{	
		$result = mysql_query("SELECT hash, title FROM hashes WHERE id=".$Files['hashid'].";", DBLINK);	

		if(@mysql_num_rows($result)>0)
			$value = mysql_fetch_row($result);
	
		$data.= " <tr class=title2 id=ignore>\n";
		$data.= "	<td colspan=3>Download Additional Pars</td>\n";
		$data.= " </tr>\n";	
		$data.= "<form action=submit.php method=post name=nzb id=nzb>";
		$data.= "<input type=hidden name=hash      value=\"".$value[0]."\">\n";
		$data.= "<input type=hidden name=srcstring value=\"".$value[1]."\">\n";
		$data.= "<input type=hidden name=from      value=\"par2.php\">\n";
		
		foreach($Files as $key => $val)
		{
			if($key=="hashid")
				continue;
				
			if(is_file(GetFile($val)))
				continue;
				
			$data.= " <tr class=main1small>\n";
			$data.= "  <td>$num<input type=checkbox name=".$key." CHECKED></td>\n";
			$data.= "  <td>".$val."</td>\n";
			$data.= "  <td>".number_format(GetFileSegTotalSize($key)/1024)." KB</td>\n";		
			$data.= " </tr>\n";	
			$num++;
		}
		
		$data.= " <tr clss=main1>\n";
		$data.= "  <td colspan=3 id=ignore><input type=Submit name=Download value=Download></td>\n";
		$data.= " </tr>\n";	
		$data.= "</form>";
	}
	
	return $data;
}
//----------------------------------------------------------------------------
function GetFile($File)
{
	global $cmd;
	$tcmd = substr($cmd, strpos($cmd, "\""));
	$tcmd = substr($tcmd, 1, strrpos($tcmd, "/"));
	
	return $tcmd.$File;
}
//----------------------------------------------------------------------------
function CheckForMoreFiles()
{
	global $log;
	unlink($log);
	
	if(count($_POST["files"])>0)
	{			
		echo "<form action=repair.php method=post name=repair id=repair>\n";
		
		foreach($_POST["files"] as $key => $val)
			echo "<input type=hidden name=\"files[]\" value=\"$val\">\n";		
		
		echo "</form>\n";		
		echo "<body onload=\"RepairNewFile()\">\n";
		
	}
	else
		header("Location: par2.php");
}
//----------------------------------------------------------------------------
?>