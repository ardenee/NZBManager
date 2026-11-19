<?php
require("nzbp.php");
$nzb = new nzb;
require("renameuploadedfiles.inc");

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
echo "   <td  colspan=2><B>Rename Files</B></td>\n";
echo "  </tr>\n";
echo "  <form action=rename.php method=post>\n";	

foreach($_POST["file_name"] as $key => $val)
{
	//echo "   <input type=hidden name=\"path[".FilterOn(PathOnly($val))."]\" value=\"".NameOnly($val)."\">\n";
	echo "  <tr class=main1>\n";															
	echo "   <td>Currnet Name:</td><td class=l1><input type=text name=\"oldname[".FilterOn($val)."]\" value=\"".NameOnly($val)."\" size=60 readonly></td>\n";
	echo "  </tr>\n";	
	echo "  <tr class=main1>\n";	
	$nname = CleanUpName(substr($val, strripos($val, "/")+1));	
	echo "   <td>New Name:</td><td class=l1><input type=text name=\"newname[".FilterOn($val)."]\" value=\"".$nname."\" size=60></td>\n";
	echo "  </tr>\n";	
}

echo "  <tr class=main1>\n";
echo "  <td colspan=2><input type=\"reset\" value=Reset> <input type=\"submit\" value=Rename>  <input type=\"button\" value=\"Skip\" onclick=\"Skip();\"></td>\n";
echo "  </tr>\n";	
echo "  <tr class=main1>\n";
echo "   <td colspan=2 height=100%>&nbsp;</td>\n";
echo "  </tr>\n";
echo $footer;
echo " </table>\n";
?>