<?php
//----------------------------------------------------------------------------
function DisplaySettings($settings)
{
	global $msg;
	$data = "  <thead id=SettingsHead>\n";
	$data.= "  <tr>\n"; 
	
	if(strlen($msg)>0)
		$msg = " - <font color=red>".$msg."</font>";
		
	$data.= "   <td class=title1 colspan=2><a onclick=\"displayRow('SettingsBody')\" style=\"cursor:hand; cursor:pointer\">Settings$msg</a></td>\n";
	$data.= "  </tr>\n";
	$data.= "  </thead>\n";
	$data.= "  <tbody id=\"SettingsBody\">\n";
	$data.= "  <form action=\"updatesettings.php\" method=\"post\">\n";
	$data.= "  <tr>\n"; 
	$data.= "   <td class=l1>nzbPlanet API Key</td>\n";
	$data.= "   <td class=l1><input type=text name=api value=\"".$settings[1]."\" size=40> (<a href=\"https://www.nzbplanet.net/apihelp\" target=\"_blank\">Whats this?</a>)</td>\n";
	$data.= "  </tr>\n";
	$data.= "  <tr>\n";
	$data.= "   <td class=l1>Autorename</td>\n";
	$data.= "   <td class=l1><input type=checkbox name=autorename".(($settings[2]==1)?" CHECKED":"")."> (Single extracted files only to nzb file name.) <b>(Not currently used)</b></td>\n";
	$data.= "  </tr>\n";	
	$data.= "  <tr>\n";
	$data.= "   <td class=l1>Temp folder</td>\n";
	$data.= "   <td class=l1><input type=text name=tmpFolder value=\"".$settings[3]."\" size=40 max=255> Temp folder for working in.</td>\n";
	$data.= "  </tr>\n";
	$data.= "  <tr>\n";
	$data.= "   <td class=l1>Save to Folder</td>\n";
	$data.= "   <td class=l1><input type=text name=saveToFolder value=\"".$settings[4]."\" size=40 max=255> Folder to load NZB files from.</td>\n";
	$data.= "  </tr>\n";
	$data.= "  <tr>\n";
	$data.= "   <td class=l1>NZB Scan Folder</td>\n";
	$data.= "   <td class=l1><input type=text name=nzbScanFolder value=\"".$settings[7]."\" size=40 max=255> Folder to save final nzb files too.</td>\n";
	$data.= "  </tr>\n";
	$data.= "  <tr>\n";
	$data.= "   <td class=l1>PAR2 Scan Folder</td>\n";
	$data.= "   <td class=l1><input type=text name=par2ScanFolder value=\"".$settings[9]."\" size=40 max=255> Folder to scan for par2 files (Should be you DownloadStation Folder).</td>\n";
	$data.= "  </tr>\n";
	$data.= "  <tr>\n";
	$data.= "   <td class=l1>Move Folders</td>\n";
	$data.= "   <td class=l1><input type=text name=MoveFolders value=\"".$settings[5]."\" size=40> Locations to move fiels too, seperated by a :</td>\n";
	$data.= "  </tr>\n";
	$data.= "  <tr>\n";
	$data.= "   <td class=l1>Save NZB dialog</td>\n";
	$data.= "   <td class=l1><input type=\"checkbox\" name=nzbSave".(($settings[6]==1)?" CHECKED":"")."> (Default: unchecked) Will always save to <b>Save to Folder</b> anyway.</td>\n";
	$data.= "  </tr>\n";	
	$data.= "  <tr>\n";
	$data.= "   <td class=l1>Clear Database</td>\n";
	$data.= "   <td class=l1><input type=\"checkbox\" name=cleardb".(($settings[8]==1)?" CHECKED":"")."> (Default: unchecked) Turncate all except setting table.</td>\n";
	$data.= "  </tr>\n";	
	$data.= "  <tr>\n";
	$data.= "   <td class=l1>Unpack after repair (RAR only)</td>\n";
	$data.= "   <td class=l1><input type=\"checkbox\" name=unpack".(($settings[10]==1)?" CHECKED":"")."> (Default: unchecked) requires unrar to be installed <b>(Not currently used)</b>.</td>\n";
	$data.= "  </tr>\n";	
	$data.= "  </tr>\n";
	$data.= "  <tr>\n";
	$data.= "   <td class=l1>Keep Main Par</td>\n";
	$data.= "   <td class=l1><input type=\"checkbox\" name=keeppar".(($settings[11]==1)?" CHECKED":"")."> (Default: unchecked) handy for repairing information if required.</td>\n";
	$data.= "  </tr>\n";	
	$data.= "  </tr>\n";
	$data.= "  <tr>\n";
	$data.= "   <td class=l1>Load File Segments</td>\n";
	$data.= "   <td class=l1><input type=\"checkbox\" name=segments".(($settings[12]==1)?" CHECKED":"")."> (Default: unchecked and slightly faster)</td>\n";
	$data.= "  </tr>\n";	
	$data.= "  </tr>\n";
	$data.= "  <tr>\n";
	$data.= "   <td class=l1>Display Name Only</td>\n";
	$data.= "   <td class=l1><input type=\"checkbox\" name=nameonly".(($settings[13]==1)?" CHECKED":"")."> (Default: unchecked) Must be formatted correctly in \"\"</td>\n";
	$data.= "  </tr>\n";
	$data.= "  <tr>\n";
	$data.= "   <td class=l1>Filters</td>\n";
	$data.= "   <td class=l1><input type=text name=filters value=\"".$settings[14]."\" size=40> Remove from uploaded nzb file name, seperated by a :</td>\n";
	$data.= "  </tr>\n";	
	$data.= "  <tr>\n";
	$data.= "   <td class=l1>Save Settings</td>\n";
	$data.= "   <td class=l1><input type=\"submit\" value=\"Save Settings\"></td>\n";
	$data.= "  </tr>\n";	
	$data.= "  </form>\n";
	$data.= " </tbody>\n";	
	
	return $data;
}
//----------------------------------------------------------------------------   
function DisplayFiles($Folder, $NZB, $Types)
{
	$files = $NZB->GetFiles(rtrim($Folder, '/'), $Types, false);	
	$data = "<table width=100% onMouseover=\"changeto(event, '#c4c4c4')\" onMouseout=\"changeback(event, '#E9E9E9')\">\n";	
	$data.= " <tr>\n";
	$data.= "  <td class=title1>Num</td>\n";
	$data.= "  <td class=title1>Title</td>\n";
	$data.= "  <td class=title1right>Size</td>\n";
	$data.= "  <td class=title1right>Date</td>\n";
	$data.= "  <td class=title1right>Options</td>\n";
	$data.= " </tr>\n";	
	$num  = 1;	
	
	foreach($files as $key => $value)
	{	
		if(in_array("par2", $Types))
		{
			$data.= " <tr>\n";
			$data.= "  <td>$num</td>\n";
			$data.= "  <td>".htmlspecialchars(substr($value, strlen($dir)))."</td>\n";
			$data.= "  <td class=right>".number_format(filesize($value))."</td>\n";
			$data.= "  <td class=right>".date("Y-m-d H:i:s", fileatime($value))."</td>\n";
			$data.= "  <td class=right><a href=\"action.php?action=repair&file=".urlencode($value)."\">Repair</a></td>\n";
			$data.= " </tr>\n";			
		}
		else if(in_array("nzb", $Types))
		{			
			$title = substr($value, (strripos($value, "/")+1));
			$title = substr($title, 0, (strlen($title)-(4)));//nzb
			$data.= " <tr>\n";
			$data.= "  <td>$num</td>\n";
			$data.= "  <td><a href=\"display.php?srcstring=".urlencode($title)."&link=".$value."\">".$title."</a></td>\n";
			$data.= "  <td class=right>".number_format(filesize($value))."</td>\n";
			$data.= "  <td class=right>".date("Y-m-d H:i:s", fileatime($value))."</td>\n";
			$data.= "  <td class=right><a href=\"renamefile.php?oldname=$value&from=index.php\">Rename</a> - <a href=\"javascript:DeleteFile('$value','index.php');\">Delete</a></td>\n";
			$data.= " </tr>\n";			
		}
		else
		{
			$data.= " <tr>\n";
			$data.= "  <td>$num</td>\n";
			$data.= "  <td>".substr($value, strlen($dir))."</td>\n";
			$data.= "  <td class=right>".number_format(filesize($value))."</td>\n";
			$data.= "  <td class=right>".date("Y-m-d H:i:s", fileatime($value))."</td>\n";
			$data.= "  <td class=right><input type=radio name=file value=\"".$value."\"> Select</td>\n";
			$data.= " </tr>\n";
		}
		
		$num++;
	}
		
	$data.= "</table>\n";
	
	echo $data;	
}
//----------------------------------------------------------------------------
function DisplayDBFiles($NZB)
{
	$files = $NZB->GetDBFiles();
	$data = "<table width=100% onMouseover=\"changeto(event, '#c4c4c4')\" onMouseout=\"changeback(event, '#E9E9E9')\">\n";
	$data.= " <tr>\n  <td class=title1>id</td>\n  <td class=title1>Title</td>\n  <td class=title1right>Hash</td>\n  <td class=title1right>Date</td>\n  <td class=title1right>Options</td>\n </tr>\n";	

	foreach($files as $key => $val)	
	{
		$data.= " <tr>\n";
		$data.= "  <td>".$val[0]."</td>\n";
		$data.= "  <td><a href=\"display.php?srcstring=".$val[4]."&hash=".$val[2]."\">".$val[4]."</a></td>\n";
		$data.= "  <td class=righthash>".$val[2]."</td>\n";
		$data.= "  <td class=right>".$val[3]."</td>\n";
		$data.= "  <td class=right><a href=\"renamedb.php?hash=".$val[2]."\">Rename</a> - <a href=\"javascript:DeleteHash('".$val[2]."','".$val[4]."');\">Delete</a></td>\n";
		$data.= " </tr>\n";				
	}
	
	$data.= "</table>\n";
	
	return $data;	
}
//----------------------------------------------------------------------------
?>
