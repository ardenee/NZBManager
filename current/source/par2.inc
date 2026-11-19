<?php
//----------------------------------------------------------------------------   
function DisplayFiles($Folder, $NZB, $Types)
{
	$files = $NZB->GetFiles(rtrim($Folder, '/'), $Types, true);	
	$files = SetUpGroups($files);		
	
	$data = " <table onMouseover=\"changeto(event, '#c4c4c4')\" onMouseout=\"changeback(event, '#E9E9E9')\">\n";
	$data.= "  <tr class=title1 id=ignore>\n";
	$data.= "   <td>Num</td>\n";
	$data.= "   <td>Folder</td>\n";
	$data.= "   <td>Location</td>\n";
	$data.= "   <td class=right>Options</td>\n";
	$data.= "  </tr>\n";	
	$num  = 1;	
	
	foreach($files as $key => $value)
	{	
		$data.= "  <form name=\"par$num\" method=post id=\"par$num\" action=repair.php>\n";
		$data.= "  <tr class=main1>\n";
		$data.= "   <td>$num</td>\n";
		$data.= "   <td><a onclick=\"displayRow('".$num."')\" style=\"cursor:hand; cursor:pointer\"><B>".MainFolderOnly(FolderOnly($value[0]))."</B></a></td>\n";			
		$data.= "   <td>".FolderOnly($value[0])."/</td>\n";
		$data.= "   <td class=right><a href=\"javascript:ParClick('par$num');\">Repair All</a></td>\n";
		$data.= "  </tr>\n";
		
		$data.=  "  <tbody id='$num'>\n";			
		$data.= "   <tr class=title2 id=ignore>\n";
		$data.= "    <td class=right>Num</td>\n";
		$data.= "    <td>File</td>\n";
		$data.= "    <td class=right>Size - Data</td>\n";
		$data.= "    <td class=right>Options</td>\n";			
		$data.= "   </tr>\n";
		$num2 = 1;
			
		foreach($value as $k => $v)
		{	
			// if valid par2 files add here
			if(!strcontains(".vol", $v))
				$data.= "    <input type=hidden name=\"files[]\" value=\"$v\">\n";
			
			$data.= "   <tr class=main1small>\n";
			$data.= "    <td class=right>$num2</td>\n";
			$data.= "    <td>$v</td>\n";
			$data.= "    <td class=right>".number_format(filesize($v)/1024)." KB - ".date("Y-m-d H:i:s", fileatime($v))."</td>\n";	
			
			//if(!strcontains(".vol", $v))			
			$data.= "   <td class=right><a href=\"repair.php?file=".urlencode($v)."\">Repair</a></td>\n";
			//else
			//	$data.= "   <td class=right>&nbsp;</td>\n";
				
			$data.= "   </tr>\n";
			$num2++;
		}
		$data.= "  </form>\n";	
		$data.= "  </tbody>\n";
		$num++;
	}
	
	$data.= "  </table>\n";
	
	echo $data;	
	
	return count($files);
}
//----------------------------------------------------------------------------
function FolderOnly($File)
{
	return substr($File, 0, strrpos($File, "/"));
}
//----------------------------------------------------------------------------
function MainFolderOnly($File)
{
	return trim(substr($File, strrpos($File, "/")), "/");
}
//----------------------------------------------------------------------------
function SetUpGroups($Files)
{
	$narray  = array();
	$narray2 = array();
	
	foreach($Files as $key => $val)
		$narray[] = FolderOnly($val);
	
	$narray = array_unique($narray);
	sort($narray);
	
	foreach($narray as $key => $val)
	{
		foreach($Files as $key2 => $val2)
		{
			if(contains($val."/", $val2))
				$narray2[$val][] = $val2;
		}
	}
	
	return($narray2);
}
//----------------------------------------------------------------------------
function strcontains($substring, $string) 
{
	$pos = stripos($string, $substring);
 
	if($pos === false)
		return false;
	else  
		return true; 
}
?>