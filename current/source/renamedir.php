<?php
	$from = "index.php";

	if(isset($_GET["from"]))
		$from = $_GET["from"];	
	else if(isset($_POST["from"]))
		$from = $_POST["from"];	
	
	foreach($_POST["oldname"] as $key=>$val)
	{	
		if($val != $_POST["newname"][$key] and strlen($_POST["newname"][$key])>0)
		{			
			$fileext  = pathinfo($_POST["path"].$val, PATHINFO_EXTENSION);			
			$filedata = pathinfo($_POST["path"].$_POST["newname"][$key]);
			
			if(strtolower($filedata['extension'])!=$fileext)
				rename($_POST["path"].$val, $_POST["path"].$_POST["newname"][$key].".".$fileext);
			else			
				rename($_POST["path"].$val, $_POST["path"].$_POST["newname"][$key]);
		}
	}

	header('Location: '.$from);
?>