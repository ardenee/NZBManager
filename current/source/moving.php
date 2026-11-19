<?php
require("nzbp.php");
require("moving.inc");
$nzb = new nzb;

echo "<!DOCTYPE HTML PUBLIC \"-//W3C//DTD HTML 4.0 Transitional//EN\">\n";
echo "<html>\n";
echo " <head>\n";
echo "  <title>NZB</title>\n";
echo "  <META http-equiv=Content-Type content=\"text/html; charset=iso-8859-1\">\n";
echo "  <LINK href=\"style.css\" type=text/css rel=stylesheet>\n";
echo "  <SCRIPT LANGUAGE=\"JavaScript\" TYPE=\"text/javascript\" SRC=\"jsstuff.js\"></SCRIPT>\n";
echo " </head>\n";

echo "<body onload=\"Expand()\">\n"; 

echo " <table height=100%>\n";
echo "  <tr class=title1>\n";
echo "   <td colspan=2>Moving Scan Folder - ".$nzb->Settings[9]."</td>\n";
echo "  </tr>\n";
echo "  <tr>\n";
echo "   <td colspan=2 height=100% valign=top>";

$count =  DisplayAllFileFolders($nzb->Settings[9], $nzb);

echo "   </td>\n";
echo "  </tr>\n";
echo $footer;	
echo " </table>\n";

echo "<script type=\"text/javascript\">\n";     
echo "function Expand()\n";
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