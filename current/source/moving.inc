<?php
//----------------------------------------------------------------------------   
function myFilter($string) 
{
  return strpos($string, '@eaDir') === false;
}
//----------------------------------------------------------------------------
function DisplayAllFileFolders($Folder, $NZB)
{
	$files = $NZB->GetFiles(rtrim($Folder, '/'), null, true);	
	$files = array_filter($files, 'myFilter');
	sort($files);	
	$files = SetUpGroups($files);	
	
	$data = " <table onMouseover=\"changeto(event, '#c4c4c4')\" onMouseout=\"changeback(event, '#E9E9E9')\">\n";
	$data.= "  <tr class=title1 id=ignore>\n";
	$data.= "   <td>Num</td>\n";
	$data.= "   <td>Folder</td>\n";
	$data.= "   <td>Location</td>\n";
	$data.= "   <td class=right>Files</td>\n";
	$data.= "   <td colspan=2 class=right>Options</td>\n";
	$data.= "  </tr>\n";	
	$data.= "  <form action=movefiles.php method=post id=movingform>\n";
		
	$num     = 1;	
	$flagged = false;	
	
	if(count($files)>0)
	{
		$data.= "  <tr class=title2 id=ignore>\n";
		$data.= "   <td colspan=6>Options</td>\n";
		$data.= "  </tr>\n";			
		$data.="   <tr class=main1 id=\"ignore\">\n";
		$data.= "   <td colspan=4><input type=button name='checkall' value=\"Invert Selection\" onclick=\"InvertSelection('movingform');\">&nbsp;<input type=button name='expand' value=\"Collapse/Expand Invert\" onclick='Expand();'> <input type=Submit value=\"Select Destination Folder\"></td>\n";
		$data.= "  </tr>\n";
	}	
	
	foreach($files as $key => $value)
	{	
		if($key==rtrim($Folder, "/"))
		{
			$data.= "  <tr class=title2 id=ignore>\n";
			$data.= "   <td colspan=6>Files</td>\n";
			$data.= "  </tr>\n";
		}
		else
		{
			if($flagged==false)
			{
				$data.="   <tr>\n";
				$data.= "   <td class=title2 colspan=6>Folders</td>\n";
				$data.= "  </tr>\n";
				$flagged = true;
			}			
		}		
	
		$data.= "  <tr class=main1>\n";
		$data.= "   <td>$num</td>\n";
		$data.= "   <td><a onclick=\"displayRow('".$num."')\" style=\"cursor:hand; cursor:pointer\"><B>".MainFolderOnly(FolderOnly($value[0]))."</B></a></td>\n";			
		$data.= "   <td>".FolderOnly($value[0])."/</td>\n";
		$data.= "   <td class=right>".count($value)."</td>\n";
		
		if($flagged)		
		{
			$data.= "   <td class=right><a href=\"javascript:DeleteFolder('".FolderOnly($value[0])."', 'moving.php');\">Delete Folder</a></td>\n";
			$data.= "   <td class=right><a href=\"javascript:RenameFolder('".FolderOnly($value[0])."', 'moving.php');\">Rename Folder</a></td>\n";
		}
		else
		{
			$data.= "   <td class=right>&nbsp;</td>\n";
			$data.= "   <td class=right>&nbsp;</td>\n";
		}
		$data.= "  </tr>\n";
		
		$data.=  "  <tbody id='$num'>\n";			
		$data.= "   <tr class=title2 id=ignore>\n";
		$data.= "    <td>Num</td>\n";
		$data.= "    <td>File</td>\n";
		$data.= "    <td class=right>Date</td>\n";	
		$data.= "    <td class=right>Size (KB)</td>\n";	
		$data.= "    <td colspan=2 class=right>Options</td>\n";			
		$data.= "   </tr>\n";
		$num2 = 1;
	
		foreach($value as $k => $v)
		{	
			if(strcontains("thumbs.db", strtolower($v)))
				continue;
				
			$data.= "   <tr class=main1small>\n";
			$data.= "    <td style=\"width:50px\">$num2&nbsp;<input type=checkbox name=\"files[]\" value=\"$v\"></td>\n";
			$data.= "    <td>".FileOnly($v)."</td>\n";
			$data.= "    <td class=right>".date("Y-m-d H:i:s", fileatime($v))."</td>\n";		
			$data.= "    <td class=right>".number_format(filesize($v))."</td>\n";		
			$data.= "    <td colspan=2 class=right><a href=\"javascript:DeleteFile('".$v."', 'moving.php');\">Delete File</a></td>\n";	
			$data.= "   </tr>\n";
			$num2++;
		}
			
		$data.= "  </tbody>\n";		
		$num++;
	}

	$data.= "  <tr class=title2 id=ignore>\n";
	$data.= "   <td colspan=6>Options</td>\n";
	$data.= "  </tr>\n";
	$data.="   <tr class=main1 id=\"ignore\">\n";
	$data.= "   <td colspan=4><input type=button name='checkall' value=\"Invert Selection\" onclick=\"InvertSelection('movingform');\">&nbsp;<input type=button name='expand' value=\"Collapse/Expand Invert\" onclick='Expand();'> <input type=Submit value=\"Select Destination Folder\"></td>\n";
	$data.= "  </tr>\n";
	$data.="   </form>\n";
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
function FileOnly($File)
{
	return trim(substr($File, strripos($File, "/")), "/");
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
			if(contains($val."/", $val2) and (substr_count($val2, "/")==(substr_count($val, "/")+1)))
				$narray2[$val][] = $val2;
		}
	}
	
	return $narray2;
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
?>