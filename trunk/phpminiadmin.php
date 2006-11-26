<?php
/*
 PHP Mini MySQL Admin
 (c) 2004-2006 Oleg Savchuk <osa@viakron.com>

 Light standalone PHP script for easy access MySQL databases.
*/

 $ACCESS_PWD=''; #script access password, SET IT if you want to protect script from public access

 #DEFAULT db connection settings
 $DB_DBNAME=""; #default DB, optional
 $DB_USER=""; #required
 $DB_PWD=""; #required
 $DB_HOST="";
 $DB_PORT="";

//constants
 $VERSION='1.3.061126';
 $MAX_ROWS_PER_PAGE=50; #max number of rows in select per one page
 $is_limited_sql=0;

 session_start();

//for debug set to 1
 ini_set('display_errors',0);
// error_reporting(E_ALL ^ E_NOTICE);

//strip quotes if they set
 if (get_magic_quotes_gpc()){
  $_POST=array_map('killmq',$_POST);
  $_GET=array_map('killmq',$_GET);
  $_COOKIE=array_map('killmq',$_COOKIE);
  $_REQUEST=array_map('killmq',$_REQUEST);
 }

 if (!$ACCESS_PWD) {
    $_SESSION['is_logged']=true;
    loadcfg();
 }

 if ($_REQUEST['login']){
    if ($_REQUEST['pwd']!=$ACCESS_PWD){
       $err_msg="Invalid password. Try again";
    }else{
       $_SESSION['is_logged']=true;
       loadcfg();
    }
 }

 if ($_REQUEST['logoff']){
    $_SESSION = array();
    session_destroy();
    $url=$_SERVER['PHP_SELF'];
    if (!$ACCESS_PWD) $url='/';
    header("location: $url");
    exit;
 }

 if (!$_SESSION['is_logged']){
    print_login();
    exit;
 }

 if ($_REQUEST['savecfg']){
    savecfg();
 }

 loadsess();

 if ($_REQUEST['showcfg']){
    print_cfg();
    exit;
 }

 //get initial values
 $SQLq=trim($_REQUEST['q']);
 $page=$_REQUEST['p']+0;
 if ($_REQUEST['refresh'] && $DB_DBNAME && !$SQLq) $SQLq="show tables";

 if (db_connect('nodie')){
    $time_start=microtime_float();
   
    if ($_REQUEST['phpinfo']){
       ob_start();
       phpinfo();
       $sqldr=ob_get_clean();
    }elseif ($_REQUEST['dp'] && $DB_DBNAME){
       perform_dump_db();
    }elseif ($_REQUEST['ex'] && $DB_DBNAME){
       perform_export_db();
    }elseif ($_REQUEST['ext'] && $DB_DBNAME){
       perform_export_table($_REQUEST['ext']);
    }else{
       if ($DB_DBNAME){
          if (!$_REQUEST['refresh'] || preg_match('/^select|show|explain/',$SQLq) ) perform_sql($SQLq,$page);  #perform non-selet SQL only if not refresh (to avoid dangerous delete/drop)
       }else{
          $err_msg="Select DB first";
       }
    }
    $time_all=ceil((microtime_float()-$time_start)*10000)/10000;
   
    print_screen();
 }else{
    print_cfg();
 }

//**************** functions

function perform_sql($q, $page=0){
 global $dbh, $DB_DBNAME, $out_message, $sqldr, $reccount, $MAX_ROWS_PER_PAGE, $is_limited_sql;
 $rc=array("o","e");

 if (preg_match("/^select|show|explain/i",$q)){
    $sql=$q;
    $is_show_tables=($q=='show tables');
    $is_show_crt=(preg_match('/^show create table/i',$q));

    if (preg_match("/^select/i",$q) && !preg_match("/limit +\d+/i", $q)){
       $offset=$page*$MAX_ROWS_PER_PAGE;
       $sql.=" LIMIT $offset,$MAX_ROWS_PER_PAGE";
       $is_limited_sql=1;
    }
    $sth=db_query($sql, 0, 'noerr');
    if($sth==0){
       $out_message = "Error ".mysql_error($dbh);
    }else{
       $reccount=mysql_num_rows($sth);
       $fields_num=mysql_num_fields($sth);
 
       $w="width='100%' ";
       if ($is_show_tables) $w='';
       $sqldr="<table border='0' cellpadding='1' cellspacing='1' $w class='res'>";
       $headers="<tr class='h'>";
       for($i=0;$i<$fields_num;$i++){
          $meta=mysql_fetch_field($sth,$i);
          $fnames[$i]=$meta->name;
          $headers.="<th>$fnames[$i]</th>";
       }
       if ($is_show_tables) $headers.="<th>show create table</th><th>explain</th><th>indexes</th><th>export</th>";
       $headers.="</tr>\n";
       $sqldr.=$headers;
       $swapper=false;
       while($hf=mysql_fetch_assoc($sth)){
         $sqldr.="<tr class='".$rc[$swp=!$swp]."'>";
         for($i=0;$i<$fields_num;$i++){
            $v=$hf[$fnames[$i]];$more='';
            if ($is_show_tables && $i==0 && $v){
               $v="<a href=\"?db=$DB_DBNAME&q=select+*+from+$v\">$v</a>".
               $more="<td>&#183;<a href=\"?db=$DB_DBNAME&q=show+create+table+$v\">sct</a></td>"
               ."<td>&#183;<a href=\"?db=$DB_DBNAME&q=explain+$v\">exp</a></td>"
               ."<td>&#183;<a href=\"?db=$DB_DBNAME&q=show+index+from+$v\">ind</a></td>"
               ."<td>&#183;<a href=\"?db=$DB_DBNAME&ext=$v\">e</a></td>";
            }
            if ($is_show_crt) $v="<pre>$v</pre>";
            $sqldr.="<td>$v".(!v?"<br />":'')."</td>";
         }
         $sqldr.="</tr>\n";
       }
       $sqldr.="</table>\n";
    }

 }
 elseif (preg_match("/^update|insert|delete|drop|truncate|alter|create/i",$q)){
    $sth = db_query($q, 0, 'noerr');
    if($sth==0){
       $out_message="Error ".mysql_error($dbh);
    }
    else{
       $reccount=mysql_affected_rows($dbh);
       $out_message="Done.";
       if (preg_match("/^insert/i",$q)) $out_message.=" New inserted id=".get_identity();
    }
 }else{
    $out_message="Please type in right SQL statements";
 }

}

function perform_dump_db(){
 global $DB_DBNAME, $sqldr, $reccount;

 $sth=db_query("show tables from $DB_DBNAME");
 while( $row=mysql_fetch_row($sth) ){
   $sth2=db_query("show create table `$row[0]`");
   $row2=mysql_fetch_row($sth2);
   $sqldr.="$row2[1];\n\n";
   $reccount++;
 }

 $sqldr="<pre>$sqldr</pre>";
}

function perform_export_db(){
 global $DB_DBNAME;

 $dr='';

 $sth=db_query("show tables from $DB_DBNAME");
 while( $row=mysql_fetch_row($sth) ){
   $dr.=perform_export_table($row[0],1)."\n";
 }

 header('Content-type: text/plain');
 header("Content-Disposition: attachment; filename=\"$DB_DBNAME.sql\"");

 echo $dr;
 exit;
}

function perform_export_table($t='',$isvar=0){
 global $dbh;
 $dr='';

 $sth=db_query("select * from `$t`");
 while( $row=mysql_fetch_row($sth) ){
   $values='';
   foreach($row as $value){
     $values.=(($values)?',':'')."'".mysql_real_escape_string($value,$dbh)."'";
   }

   $dr.="INSERT INTO `$t` VALUES ($values);\n";
 }

 if ($isvar){
    return $dr;
 }else{
    header('Content-type: text/plain');
    header("Content-Disposition: attachment; filename=\"$t.sql\"");
   
    echo $dr;
    exit;
 }
}

function print_header(){
 global $err_msg,$VERSION,$DB_DBNAME,$dbh;
?>
<html>
<head>
<style type="text/css">
body,th,td{font-family:Arial,Helvetica,sans-serif;font-size:80%;padding:0px;margin:0px}
div{padding:3px}
.inv{background-color:#006699;color:#FFFFFF}
.inv a{color:#FFFFFF}
table.res tr{vertical-align:top}
tr.e{background-color:#CCCCCC}
tr.o{background-color:#EEEEEE}
tr.h{background-color:#9999CC}
.err{color:#FF3333;font-weight:bold;text-align:center}
</style>

<script type="text/javascript">
function frefresh(){
 var F=document.DF;
 F.method='get';
 F.refresh.value="1";
 F.submit();
}
function go(p,sql){
 var F=document.DF;
 F.p.value=p;
 if(sql)F.q.value=sql;
 F.submit();
}
function chksql(){
 var F=document.DF;
 if(/^\s*(?:delete|drop|truncate|alter)/.test(F.q.value)) return confirm('Are you sure to continue?');
}
</script>

</head>
<body>
<form method="post" name="DF" action="<?=$_SERVER['PHP_SELF']?>">
<input type="hidden" name="refresh" value="">
<input type="hidden" name="p" value="">

<div class="inv">
<b>phpMiniAdmin <?=$VERSION?></b>
<? if ($_SESSION['is_logged'] && $dbh){ ?>
 | 
Database: <select name="db" onChange="frefresh()">
<option value='*'> - select/refresh -
<?=get_db_select($DB_DBNAME)?>
</select>
<? if($DB_DBNAME){ ?>
 &#183;<a href="<?=$_SERVER['PHP_SELF']?>?db=<?=$DB_DBNAME?>&q=show+tables">show tables</a>
 &#183;<a href="<?=$_SERVER['PHP_SELF']?>?db=<?=$DB_DBNAME?>&dp=1">dump structure</a>
 &#183;<a href="<?=$_SERVER['PHP_SELF']?>?db=<?=$DB_DBNAME?>&ex=1">export data</a>
<? } ?>
 | <a href="?showcfg=1">Settings</a> 
<?} ?>
<?if ($GLOBALS['ACCESS_PWD']){?> | <a href="?logoff=1">Logoff</a> <?}?>
 | <a href="?phpinfo=1">phpinfo</a>
</div>

<div class="err"><?=$err_msg?></div>

<?
}

function print_screen(){
 global $out_message, $SQLq, $err_msg, $reccount, $time_all, $sqldr, $page, $MAX_ROWS_PER_PAGE, $is_limited_sql;

 print_header();

?>

<center>
<div style="width:500px;" align="left">
SQL-query:<br />
<textarea name="q" cols="70" rows="10"><?=$SQLq?></textarea>
<input type=submit name="GoSQL" value="Go" onclick="return chksql()" style="width:100px">&nbsp;&nbsp;
<input type=button name="Clear" value=" Clear " onClick="document.DF.q.value=''" style="width:100px">
</div>
</center>
<hr />

Records: <b><?=$reccount?></b> in <b><?=$time_all?></b> sec<br />
<b><?=$out_message?></b>

<hr />
<?
 if ($is_limited_sql && ($page || $reccount>=$MAX_ROWS_PER_PAGE) ){
  echo "<center>";
  echo make_List_Navigation($page, 10000, $MAX_ROWS_PER_PAGE, "javascript:go(%p%)");
  echo "</center>";
 }
#$reccount
?>
<?=$sqldr?>

<?
 print_footer();
}

function print_footer(){
?>
</form>
<br>
<br>

<div align="right">
<small>&copy; 2004-2006 Oleg Savchuk</small>
</div>
</body></html>
<?
}

function print_login(){

 print_header();
?>

<center>
<h3>Access protected by password</h3>
<div style="width:400px;border:1px solid #999999;background-color:#eeeeee">
Password: <input type="password" name="pwd" value="">
<input type="hidden" name="login" value="1">
<input type="submit" value=" Login ">
</div>
</center>

<?
 print_footer();
}


function print_cfg(){
 global $DB_DBNAME,$DB_HOST,$DB_USER,$DB_PWD,$err_msg;

 print_header();
?>

<center>
<h3>DB Connection Settings</h3>
<div style="width:400px;border:1px solid #999999;background-color:#eeeeee;text-align:left">
MySQL host: <input type="text" name="host" value="<?=$DB_HOST?>"> port: <input type="text" name="port" value="<?=$DB_PORT?>" size="4"><br />
User name: <input type="text" name="user" value="<?=$DB_USER?>"><br />
Password: <input type="password" name="pwd" value=""><br />
DB name: <input type="text" name="db" value="<?=$DB_DBNAME?>"><br />
<input type="checkbox" name="rmb" value="1" checked> Remember in cookies for 30 days
<input type="hidden" name="savecfg" value="1">
<input type="submit" value=" Apply "><input type="button" value=" Cancel " onclick="window.location='<?=$_SERVER['PHP_SELF']?>'">
</div>
</center>

<?
 print_footer();
}


//******* utilities
function db_connect($nodie=0){
 global $dbh,$DB_DBNAME,$DB_HOST,$DB_PORT,$DB_USER,$DB_PWD,$err_msg;

 $dbh=mysql_connect($DB_HOST.($DB_PORT?":$DB_PORT":''),$DB_USER,$DB_PWD);
 if (!$dbh) {
    $err_msg='Cannot connect to the database because: '.mysql_error();
    if (!$nodie) die($err_msg);
 }

 if ($dbh && $DB_DBNAME) {
  $res=mysql_select_db($DB_DBNAME, $dbh);
  if (!$res) {
     $err_msg='Cannot select db because: '.mysql_error();
     if (!$nodie) die($err_msg);
  }
 }

 return $dbh;
}

function db_checkconnect($dbh1=NULL){
 global $dbh;
 if (!$dbh1) $dbh1=&$dbh;
 if (!$dbh1 or !mysql_ping($dbh1)) {
    db_connect();
    $dbh1=&$dbh;
 }
 return $dbh1;
}

function db_disconnect(){
 global $dbh;
 mysql_close($dbh);
}

function db_query($sql, $dbh1=NULL, $skiperr=0){
 $dbh1=db_checkconnect($dbh1);
 $sth=mysql_query($sql, $dbh1);
 if (!$sth && $skiperr) return;
 catch_db_err($dbh1, $sth, $sql);
 return $sth;
}

function catch_db_err($dbh, $sth, $sql=""){
 if (!$sth) die("Error in DB operation:<br>\n".mysql_error($dbh)."<br>\n$sql");
}

function get_identity($dbh1=NULL){
 $dbh1=db_checkconnect($dbh1);
 return mysql_insert_id($dbh1);
}

function get_db_select($sel=''){
 $result='';
 if ($_SESSION['sql_show_databases'] && !$_REQUEST['db']=='*'){//check cache
    $arr=$_SESSION['sql_show_databases'];
 }else{
   $sth=db_query("show databases");
   $arr=array();
   while(list($a)=mysql_fetch_row($sth)){
     $arr[]=$a;
   }
   $_SESSION['sql_show_databases']=$arr;
 }

 foreach($arr as $a)
     $result.="<option value='$a' ".($sel && $sel==$a?'selected':'').">$a</option>";

 return $result;
}

function microtime_float(){
 list($usec,$sec)=explode(" ",microtime()); 
 return ((float)$usec+(float)$sec); 
} 

############################
# $pg=int($_[0]);     #current page
# $all=int($_[1]);     #total number of items
# $PP=$_[2];      #number if items Per Page
# $ptpl=$_[3];      #page url /ukr/dollar/notes.php?page=    for notes.php
# $show_all=$_[5];           #print Totals?
function make_List_Navigation($pg, $all, $PP, $ptpl, $show_all=''){
  $n='&nbsp;';
  $sep=" $n|$n\n";
  if (!$PP) $PP=10;
  $allp=floor($all/$PP+0.999999);

  $pname='';
  $res='';
  $w=array('Less','More','Back','Next','First','Total');

  $sp=$pg-2;
  if($sp<0) $sp=0;
  if($allp-$sp<5 && $allp>=5) $sp=$allp-5;

  $res="";

  if($sp>0){
    $pname=pen($sp-1,$ptpl);
    $res.="<a href='$pname'>$w[0]</a>";       
    $res.=$sep;
  }
  for($p_p=$sp;$p_p<$allp && $p_p<$sp+5;$p_p++){
     $first_s=$p_p*$PP+1;
     $last_s=($p_p+1)*$PP;
     $pname=pen($p_p,$ptpl);
     if($last_s>$all){
       $last_s=$all;
     }      
     if($p_p==$pg){
        $res.="<b>$first_s..$last_s</b>";
     }else{
        $res.="<a href='$pname'>$first_s..$last_s</a>";
     }       
     if($p_p+1<$allp) $res.=$sep;
  }
  if($sp+5<$allp){
    $pname=pen($sp+5,$ptpl);
    $res.="<a href='$pname'>$w[1]</a>";       
  }
  $res.=" <br>\n";

  if($pg>0){
    $pname=pen($pg-1,$ptpl);
    $res.="<a href='$pname'>$w[2]</a> $n|$n ";
    $pname=pen(0,$ptpl);
    $res.="<a href='$pname'>$w[4]</a>";   
  }
  if($pg>0 && $pg+1<$allp) $res.=$sep;
  if($pg+1<$allp){
    $pname=pen($pg+1,$ptpl);
    $res.="<a href='$pname'>$w[3]</a>";    
  }    
  if ($show_all) $res.=" <b>($w[5] - $all)</b> ";

  return $res;
}

function pen($p,$np=''){
 return str_replace('%p%',$p, $np);
}

function killmq($value){
 return is_array($value)?array_map('killmq',$value):stripslashes($value);
}

function savecfg(){
 $_SESSION['db']  =$_REQUEST['db']  ;
 $_SESSION['user']=$_REQUEST['user'];
 $_SESSION['pwd'] =$_REQUEST['pwd'] ;
 $_SESSION['host']=$_REQUEST['host'];
 $_SESSION['port']=$_REQUEST['port'];

 if ($_REQUEST['rmb']){
    $tm=time()+60*60*24*30;
    setcookie("conn[db]",  $_REQUEST['db'],$tm);
    setcookie("conn[user]",$_REQUEST['user'],$tm);
    setcookie("conn[pwd]", $_REQUEST['pwd'],$tm);
    setcookie("conn[host]",$_REQUEST['host'],$tm);
    setcookie("conn[port]",$_REQUEST['port'],$tm);
 }else{
    setcookie("conn[db]",  FALSE,-1);
    setcookie("conn[user]",FALSE,-1);
    setcookie("conn[pwd]", FALSE,-1);
    setcookie("conn[host]",FALSE,-1);
    setcookie("conn[port]",FALSE,-1);
 }
}

//during login only - from cookies or use defaults;
function loadcfg(){
 global $DB_DBNAME,$DB_HOST,$DB_PORT,$DB_USER,$DB_PWD;

 if( isset($_COOKIE['conn']) ){
    $a=$_COOKIE['conn'];
    $_SESSION['db']=$a['db'];
    $_SESSION['user']=$a['user'];
    $_SESSION['pwd']=$a['pwd'];
    $_SESSION['host']=$a['host'];
    $_SESSION['port']=$a['port'];
 }else{
    $_SESSION['db']=$DB_DBNAME;
    $_SESSION['user']=$DB_USER;
    $_SESSION['pwd']=$DB_PWD;
    $_SESSION['host']=$DB_HOST;
    $_SESSION['port']=$DB_PORT;
 }
}

//each time - from session to $DB_*
function loadsess(){
 global $DB_DBNAME,$DB_HOST,$DB_PORT,$DB_USER,$DB_PWD;

 $isd=0;
 $rdb=$_REQUEST['db'];
 if ($rdb=='*') $rdb='';
 if ($rdb) {
    $DB_DBNAME=$rdb;
    $isd=1;
 }

 if (!$isd) $DB_DBNAME=$_SESSION['db'];
 $DB_USER=$_SESSION['user'];
 $DB_PWD=$_SESSION['pwd'];
 $DB_HOST=$_SESSION['host'];
 $DB_PORT=$_SESSION['port'];
}
?>