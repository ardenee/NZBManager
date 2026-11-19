<?php
require("nzbp.php");
require("unpacking.inc");
$nzb = new nzb;

echo "<!DOCTYPE HTML PUBLIC \"-//W3C//DTD HTML 4.0 Transitional//EN\">\n";
echo "<html>\n";
echo " <head>\n";
echo "  <title>NZB</title>\n";
echo "  <META http-equiv=Content-Type content=\"text/html; charset=iso-8859-1\">\n";
echo "  <SCRIPT LANGUAGE=\"JavaScript\" TYPE=\"text/javascript\" SRC=\"jsstuff.js\"></SCRIPT>\n";
echo "  <LINK href=\"style.css\" type=text/css rel=stylesheet>\n";
echo " </head>\n";

echo "<body onload=\"displayAll()\">\n"; 
echo " <table height=100%>\n";
echo "  <tr>\n";
echo "   <td colspan=2 class=title1>Unpacking Scan Folder - ".$nzb->Settings[9]."</td>\n";
echo "  </tr>\n";
echo "  <tr class=title2>\n";
echo "   <td colspan=2>Requires urar, unzip or tar installed to /opt/bin/</td>\n";
echo "  </tr>\n";
echo "  <tr>\n";
echo "   <td colspan=2 height=100% class=main1 valign=top>";

$types = array("rar", "zip", "tar", "tgz", "spk");
$count =  DisplayFiles($nzb->Settings[9], $nzb, $types);

echo "   </td>\n";
echo "  </tr>\n";
echo $footer;	
echo " </table>\n";

echo "<script type=\"text/javascript\">\n";     
echo "function displayAll()\n";
echo "{\n";  
echo "  var i=1;\n";
echo "  var row;\n";
echo "  for(i=1;i<".($count+1).";i++)\n";
echo "  {\n";
echo "    row = document.getElementById(i);\n";  
echo "    if (row.style.display == '')\n";   
echo "      row.style.display = 'none';\n"; 	
echo "    else\n";  
echo "      row.style.display = '';\n";   
echo "  }\n";
echo "}\n"; 
echo "</script>\n"; 

echo "</html>";
?>