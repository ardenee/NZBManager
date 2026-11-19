<?php
require("nzbp.php");
$nzb = new nzb;

$oldname = $_GET["oldname"];
$from    = $_GET["from"];
$path    = substr($oldname, 0, strripos($oldname, "/")+1);
$name    = substr($oldname, strripos($oldname, "/")+1);

echo "<!DOCTYPE HTML PUBLIC \"-//W3C//DTD HTML 4.0 Transitional//EN\">\n";
echo "<html>\n";
echo " <head>\n";
echo "  <title>NZB</title>\n";
echo "  <META http-equiv=Content-Type content=\"text/html; charset=iso-8859-1\">\n";
echo "  <LINK href=\"style.css\" type=text/css rel=stylesheet>\n";
echo "  <SCRIPT LANGUAGE=\"JavaScript\" TYPE=\"text/javascript\" SRC=\"jsstuff.js\"></SCRIPT>\n";
echo " </head>\n";

echo " <table height=100%>\n";
echo "  <tr class=title1>\n";
echo "   <td colspan=2><B>Rename File</B></td>\n";
echo "  </tr>\n";
echo "  <form action=rename.php method=post>\n";	
echo "   <input type=hidden name=from value=\"$from\">\n";
echo "  <tr class=main1>\n";
echo "   <td>Currnet Name:</td>\n";
echo "   <td><input type=text name=\"oldname[".FilterOn($oldname)."]\" value=\"".$name."\" size=60 readonly></td>\n";
echo "  </tr>\n";	
echo "  <tr class=main1>\n";	
echo "   <td>New Name:</td>\n";
echo "   <td><input type=text name=\"newname[".FilterOn($oldname)."]\" value=\"".CleanUpName($name)."\" size=60></td>\n";
echo "  </tr>\n";	
echo "  <tr class=main1>\n";
echo "  <td colspan=2><input type=\"reset\" value=Reset> <input type=\"submit\" value=Rename></td>\n";
echo "  </tr>\n";	
echo "  </form>\n";
echo "  <tr>\n";
echo "   <td height=100% colspan=2>&nbsp;</td>\n";
echo "  </tr>\n";
echo $footer;
echo " </table>\n";

//----------------------------------------------------------------------------
function FilterOn($name)
{
	$nname = str_ireplace("[", "{{", $name);
	$nname = str_ireplace("]", "}}", $nname);
	
	return $nname;
}
//----------------------------------------------------------------------------

function GetKeywords()
{
}
//----------------------------------------------------------------------------
function StripName($name)
{
	$nname = $name;
	$nname = substr($name, (strpos($name, "\"")+1));
	$nname = substr($nname, 0, strrpos($nname, "\""));		
	
	return $nname;	
}
//----------------------------------------------------------------------------
function CleanUpName($Name)
{
	global $nzb;
	
	$file_parts = pathinfo($Name);	
	$a          = explode(":", $nzb->Settings[14]);
	$nname      = str_ireplace(".", "@", $file_parts['filename']);
	
	foreach($a as $key =>$val)
	{
		$nname = str_ireplace("@".$val, "", $nname);
		$nname = str_ireplace($val."@", "", $nname);
		$nname = str_ireplace("-".$val, "", $nname);
		$nname = str_ireplace($val."-", "", $nname);
	}
	
	$nname = str_ireplace("  ", "", $nname);
	$nname = str_ireplace("@", " ", $nname);
	$nname = trim($nname, "-");
	$nname = trim($nname, ".");
	$nname = trim($nname);
	
	if(strlen($file_parts['extension'])>0)
		$nname = $nname.".".strtolower($file_parts['extension']);	

	return $nname;
}
//----------------------------------------------------------------------------

?>