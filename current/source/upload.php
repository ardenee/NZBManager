<?php
include("upload.inc");
$folder = "/volume1/Data/nzb/scan/";

if(isset($_POST["saved"]))
	$folder = "/volume1/Data/nzb/saved/";
	
echo "<html>";	
echo " <head>";
echo "<script type=\"text/javascript\">\n"; 
echo "function SubmitForm() {\n"; 
echo " document.getElementById('form').submit();\n"; 
echo "}\n"; 
echo "</script>\n"; 
echo " </head>";

echo " <body onload=\"SubmitForm();\">\n";
echo " <form action=\"renameuploadedfiles.php\" id=\"form\" name=\"form\" method=\"post\">\n";

foreach($_FILES["files"]["error"] as $key => $error) 
{
	if($error == UPLOAD_ERR_OK) 
	{
		$tmp_name = $_FILES["files"]["tmp_name"][$key];
		$name     = $_FILES["files"]["name"][$key];
		$newname  = GetValidName($folder.$name);
		move_uploaded_file($tmp_name, $newname);
		echo " <input type=\"hidden\" name=\"file_name[]\" value=\"$newname\">\n";		
	}
}
		
echo "</form>\n";
echo "</html>";			
?>