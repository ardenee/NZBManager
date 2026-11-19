if (!Array.prototype.indexOf) 
{ 
  Array.prototype.indexOf = function(elt /*, from*/) 
  { 
    var len = this.length >>> 0; 
    var from = Number(arguments[1]) || 0; 
    from = (from < 0) ? Math.ceil(from) : Math.floor(from); 
    if (from < 0) 
      from += len; 
    for (; from < len; from++) 
    { 
      if (from in this && this[from] === elt) 
        return from; 
    } 
    return -1; 
  } 
} 

function UnpackClick(val){
    document.getElementById(val).submit();
}

function Skip(){
    window.open("index.php", "_self")
}

function DeleteFile(file, from){
  var answer=confirm("Delete File?\n\n"+file);
  if (answer==true)
  {
    window.open("deletefile.php?from="+from+"&file="+file, "_self")
  }
}

function DeleteFolder(folder, from){
  var answer=confirm("Delete Folder?\n\n"+folder);
  if (answer==true)
  {
    window.open("deletefolder.php?from="+from+"&folder="+folder, "_self");
  }
}

function RenameFolder(folder, from){
    window.open("renamefolder.php?from="+from+"&oldname="+folder, "_self");
}

function DeleteHash(hash, title){
  var answer=confirm("Delete '"+title+"'\nwith a hash of '"+hash+"'\nfrom DB?");
  if (answer==true)
  {
    window.open("deletehash.php?hash="+hash,"_self");
  }
}

function displayRow(num){  
  var row = document.getElementById(num);  
    if (row.style.display == '')   
      row.style.display = 'none'; 	
    else  
      row.style.display = '';   
} 

function ParClick(val){
    document.getElementById(val).submit();
}

var highlightbehavior = "TR"
var ns6               = document.getElementById && !document.all
var ie                = document.all

function changeto(e, highlightcolor){
  source = ie ? event.srcElement : e.target
  
  if (source.tagName == "TABLE")
    return
	
  while(source.tagName != highlightbehavior && source.tagName != "HTML")
    source = ns6 ? source.parentNode : source.parentElement
	
  if (source.style.backgroundColor != highlightcolor && source.id != "ignore")
    source.style.backgroundColor = highlightcolor
}

function contains_ns6(master, slave) { //check if slave is contained by master
  while (slave.parentNode)
    if ((slave = slave.parentNode) == master)
      return true;
	  
  return false;
}

function changeback(e, originalcolor){
  if (ie &&(event.fromElement.contains(event.toElement) || source.contains(event.toElement) || source.id == "ignore") || source.tagName == "TABLE")
    return
  else if (ns6 &&(contains_ns6(source, e.relatedTarget) || source.id == "ignore"))
    return

	
  if (ie && event.toElement != source || ns6 && e.relatedTarget != source)
	source.style.backgroundColor = originalcolor
}

function handleClick(val){
    document.getElementById('folder').value = val;
    document.getElementById('merge').submit();
}

function ReSubmitReport(formname){
  sleep(2000);
  document.getElementById(formname).submit();
}
		
function sleep(milliseconds) {
  var start = new Date().getTime();
  for (var i = 0; i < 1e7; i++) {
    if ((new Date().getTime() - start) > milliseconds){
      break;
    }
  }
}

function UnpackNewFile(){
  sleep(2000);
  document.getElementById('unpack').submit();
}

var checked = true; 
function checkedAll(val) { 
	if (checked == false) 
	  {checked = true} 
	else 
	  {checked = false} 
	for (var i = 0; i < document.getElementById(val).elements.length; i++) 
	  document.getElementById(val).elements[i].checked = checked; 
}


function InvertSelection(val) { 
	for (var i = 0; i < document.getElementById(val).elements.length; i++) 
	{
		if(document.getElementById(val).elements[i].checked)
			document.getElementById(val).elements[i].checked = false;
		else
			document.getElementById(val).elements[i].checked = true;
	}
}

function RepairNewFile(){
  sleep(2000);
  document.getElementById('repair').submit();
}

function ReSubmitUnpack(){
    document.getElementById('unpack').submit();
}

function CreateFolder(){
var name=prompt('Please enter New Folder name', 'New Folder');
if (name!=null && name!="")
  {
    document.getElementById('newfolder').value = name;
    document.getElementById('selectfolder').submit();
  }
}

function MoveClick(val){
    document.getElementById('folder').value = val;
    document.getElementById('selectfolder').submit();
}