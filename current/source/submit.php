<?php
	require("nzbp.php");
	$nzb = new nzb;
	$from = "index.php";
	
	if(isset($_POST['from']))
		$from = $_POST['from'];
	
	header("Content-type: application/octet-stream"); 
	header("Cache-Control: no-cache, must-revalidate"); 
	header("Expires: 0"); 
	
	$file = MakeNZBFileFromDB($_POST['hash']);
	
	$file = "<?xml version=\"1.0\" encoding=\"iso-8859-1\" ?>\n"
		   ."<!DOCTYPE nzb PUBLIC \"-//newzBin//DTD NZB 1.1//EN\" \"http://www.newzbin2.es/DTD/nzb/nzb-1.1.dtd\">\n"
		   ."<nzb xmlns=\"http://www.newzbin2.es/DTD/2003/nzb\">\n"; 
 
	$result = mysql_query("SELECT * FROM hashes WHERE hash='".$_POST['hash']."';", DBLINK);	
	$h_id   = mysql_fetch_row($result);	
	
	if(isset($h_id[0]))
	{	
		$result = mysql_query("SELECT * FROM meta WHERE hashid=".$h_id[0].";", DBLINK);
			
		if(mysql_num_rows($result)>0)
		{		
			$file .= " <head>\n";
				
			while($h_data = mysql_fetch_row($result))
				$file .= "  <meta type=\"".str_replace('&#039;', '&apos;', htmlspecialchars($h_data[2], ENT_QUOTES))."\">".str_replace('&#039;', '&apos;', htmlspecialchars($h_data[3], ENT_QUOTES))."</meta>\n";					
				
			$file .= " </head>\n";
		}
				
		foreach($_POST as $key => $val)
		{
			if($key=="hash" or $key=="srcstring" or $key=="checkall" or $key=="checkpars")
				continue;				
							
			$result = mysql_query("SELECT * FROM files WHERE hashid=".$h_id[0]." AND id=".$key.";", DBLINK);
					
			while($f_data = mysql_fetch_row($result))
			{		
				$file .= " <file poster=\"".str_replace('&#039;', '&apos;', htmlspecialchars($f_data[2], ENT_QUOTES))."\" date=\"".$f_data[3]."\" subject=\"".str_replace('&#039;', '&apos;', htmlspecialchars($f_data[4], ENT_QUOTES))."\">\n";
				$file .= "  <groups>\n";
				$g_array = array();
				$g_array = explode(",", $f_data[5]);
				
				foreach($g_array as $val)
					$file .= "   <group>$val</group>\n";
				
				$file .= "  </groups>\n";
				$result2 = mysql_query("SELECT * FROM segments WHERE fileid=".$f_data[0].";", DBLINK);	
				$file .= "  <segments>\n";
				
				while($s_data = mysql_fetch_row($result2))
					$file .= "   <segment bytes=\"".$s_data[3]."\" number=\"".$s_data[4]."\">".str_replace('&#039;', '&apos;', htmlspecialchars($s_data[5], ENT_QUOTES))."</segment>\n";
				
				$file .= "  </segments>\n";
				$file .= " </file>\n";	
			}
		}
		
		$file .= "</nzb>\n";	

		if(file_exists($nzb->Settings[4].urldecode($_POST['srcstring']).".nzb"))
		{
			$num = 1;	
						
			while(file_exists($nzb->Settings[4].urldecode($_POST['srcstring'])."(".$num.").nzb"))
				$num++;			
		
			file_put_contents($nzb->Settings[4].urldecode($_POST['srcstring'])."(".$num.").nzb", $file);			
		}
		else
			file_put_contents($nzb->Settings[4].urldecode($_POST['srcstring']).".nzb", $file);
		
		if($nzb->Settings[8]==1)
		{
			header('Content-Disposition: attachment; filename="'.urldecode($_POST['srcstring']).'.nzb"'); 					
			print $file; 
		}
		else
			header('Location: '.$from);
	}
?>