<?php
/*
 PHP Mini MySQL Admin
 (c) 2004-2007 Oleg Savchuk <osa@viakron.com>
 Charset support - thanks to Alex Didok http://www.main.com.ua

 Light standalone PHP script for easy access MySQL databases.
 http://phpminiadmin.sourceforge.net
*/

 $ACCESS_PWD=''; #script access password, SET IT if you want to protect script from public access

 #DEFAULT db connection settings
 $DB=array(
 'user'=>"",#required
 'pwd'=>"", #required
 'db'=>"",  #default DB, optional
 'host'=>"",#optional
 'port'=>"",#optional
 'chset'=>"",#default charset, optional
 );

//constants
 $VERSION='1.3.070204';
 $MAX_ROWS_PER_PAGE=50; #max number of rows in select per one page
 $is_limited_sql=0;
 $self=$_SERVER['PHP_SELF'];

 session_start();

//for debug set to 1
 ini_set('display_errors',0);
// error_reporting(E_ALL ^ E_NOTICE);

//strip quotes if they set
 if (get_magic_quotes_gpc()){
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
    $url=$self;
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
 if ($_REQUEST['refresh'] && $DB['db'] && !$SQLq) $SQLq="show tables";

 if (db_connect('nodie')){
    $time_start=microtime_float();
   
    if ($_REQUEST['phpinfo']){
       ob_start();
       phpinfo();
       $sqldr=ob_get_clean();
    }elseif ($_REQUEST['dp'] && $DB['db']){
       perform_dump_db();
    }elseif ($_REQUEST['ex'] && $DB['db']){
       perform_export_db();
    }elseif ($_REQUEST['ext'] && $DB['db']){
       perform_export_table($_REQUEST['ext']);
    }else{
       if ($DB['db']){
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
 global $dbh, $DB, $out_message, $sqldr, $reccount, $MAX_ROWS_PER_PAGE, $is_limited_sql;
 $rc=array("o","e");
 $dbn=$DB['db'];

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
       if ($is_show_tables) $headers.="<th>show create table</th><th>explain</th><th>indexes</th><th>export</th><th>drop</th><th>truncate</th>";
       $headers.="</tr>\n";
       $sqldr.=$headers;
       $swapper=false;
       while($hf=mysql_fetch_assoc($sth)){
         $sqldr.="<tr class='".$rc[$swp=!$swp]."'>";
         for($i=0;$i<$fields_num;$i++){
            $v=$hf[$fnames[$i]];$more='';
            if ($is_show_tables && $i==0 && $v){
               $v="<a href=\"?db=$dbn&q=select+*+from+$v\">$v</a>".
               $more="<td>&#183;<a href=\"?db=$dbn&q=show+create+table+$v\">sct</a></td>"
               ."<td>&#183;<a href=\"?db=$dbn&q=explain+$v\">exp</a></td>"
               ."<td>&#183;<a href=\"?db=$dbn&q=show+index+from+$v\">ind</a></td>"
               ."<td>&#183;<a href=\"?db=$dbn&ext=$v\">e</a></td>"
               ."<td>&#183;<a href=\"?db=$dbn&q=drop+table+$v\" onclick='return ays()'>drop</a></td>"
               ."<td>&#183;<a href=\"?db=$dbn&q=truncate+table+$v\" onclick='return ays()'>trunc</a></td>";
            }
            if ($is_show_crt) $v="<pre>$v</pre>";
            $sqldr.="<td>$v".(!v?"<br />":'')."</td>";
         }
         $sqldr.="</tr>\n";
       }
       $sqldr.="</table>\n";
    }

 }
 elseif (preg_match("/^update|insert|replace|delete|drop|truncate|alter|create/i",$q)){
    $sth = db_query($q, 0, 'noerr');
    if($sth==0){
       $out_message="Error ".mysql_error($dbh);
    }
    else{
       $reccount=mysql_affected_rows($dbh);
       $out_message="Done.";
       if (preg_match("/^insert|replace/i",$q)) $out_message.=" New inserted id=".get_identity();
       if (preg_match("/^drop|truncate/i",$q)) perform_sql("show tables");
    }
 }else{
    $out_message="Please type in right SQL statements";
 }

}

function perform_dump_db(){
 global $DB, $sqldr, $reccount;

 $sth=db_query("show tables from $DB[db]");
 while( $row=mysql_fetch_row($sth) ){
   $sth2=db_query("show create table `$row[0]`");
   $row2=mysql_fetch_row($sth2);
   $sqldr.="$row2[1];\n\n";
   $reccount++;
 }

 $sqldr="<pre>$sqldr</pre>";
}

function perform_export_db(){
 global $DB;

 header('Content-type: text/plain');
 header("Content-Disposition: attachment; filename=\"$DB[db].sql\"");

 $sth=db_query("show tables from $DB[db]");
 while( $row=mysql_fetch_row($sth) ){
   perform_export_table($row[0],1);
   echo "\n";
 }

 exit;
}

function perform_export_table($t='',$isvar=0){
 global $dbh;
 set_time_limit(600);

 if (!$isvar){
    header('Content-type: text/plain');
    header("Content-Disposition: attachment; filename=\"$t.sql\"");
 }

 $sth=db_query("select * from `$t`");
 while( $row=mysql_fetch_row($sth) ){
   $values='';
   foreach($row as $value){
     $values.=(($values)?',':'')."'".mysql_real_escape_string($value,$dbh)."'";
   }
   echo "INSERT INTO `$t` VALUES ($values);\n";
 }

 if (!$isvar){
    exit;
 }
}

function print_header(){
 global $err_msg,$VERSION,$DB,$dbh,$self;
 $dbn=$DB['db'];
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
function ays(){
 return confirm('Are you sure to continue?');
}
function chksql(){
 var F=document.DF;
 if(/^\s*(?:delete|drop|truncate|alter)/.test(F.q.value)) return ays();
}
</script>

</head>
<body>
<form method="post" name="DF" action="<?=$self?>">
<input type="hidden" name="refresh" value="">
<input type="hidden" name="p" value="">

<div class="inv">
<a href="http://sourceforge.net/projects/phpminiadmin/" target="_blank"><b>phpMiniAdmin <?=$VERSION?></b></a>
<? if ($_SESSION['is_logged'] && $dbh){ ?>
 | 
Database: <select name="db" onChange="frefresh()">
<option value='*'> - select/refresh -
<?=get_db_select($dbn)?>
</select>
<? if($dbn){ ?>
 &#183;<a href="<?=$self?>?db=<?=$dbn?>&q=show+tables">show tables</a>
 &#183;<a href="<?=$self?>?db=<?=$dbn?>&dp=1">dump structure</a>
 &#183;<a href="<?=$self?>?db=<?=$dbn?>&ex=1">export data</a>
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
 global $DB,$err_msg,$self;

 print_header();
?>

<center>
<h3>DB Connection Settings</h3>
<div style="width:400px;border:1px solid #999999;background-color:#eeeeee;text-align:left">
User name: <input type="text" name="v[user]" value="<?=$DB['user']?>"><br />
Password: <input type="password" name="v[pwd]" value=""><br />
MySQL host: <input type="text" name="v[host]" value="<?=$DB['host']?>"> port: <input type="text" name="v[port]" value="<?=$DB['port']?>" size="4"><br />
DB name: <input type="text" name="v[db]" value="<?=$DB['db']?>"><br />
Charset: <select name="v[chset]"><option value="">- default -</option><?=chset_select($DB['chset'])?></select><br />
<input type="checkbox" name="rmb" value="1" checked> Remember in cookies for 30 days
<input type="hidden" name="savecfg" value="1">
<input type="submit" value=" Apply "><input type="button" value=" Cancel " onclick="window.location='<?=$self?>'">
</div>
</center>

<?
 print_footer();
}


//******* utilities
function db_connect($nodie=0){
 global $dbh,$DB,$err_msg;

 $dbh=@mysql_connect($DB['host'].($DB['port']?":$DB[port]":''),$DB['user'],$DB['pwd']);
 if (!$dbh) {
    $err_msg='Cannot connect to the database because: '.mysql_error();
    if (!$nodie) die($err_msg);
 }

 if ($dbh && $DB['db']) {
  $res=mysql_select_db($DB['db'], $dbh);
  if (!$res) {
     $err_msg='Cannot select db because: '.mysql_error();
     if (!$nodie) die($err_msg);
  }else{
     if ($DB['chset']) db_query("SET NAMES ".$DB['chset']);
  }
 }

 return $dbh;
}

function db_checkconnect($dbh1=NULL, $skiperr=0){
 global $dbh;
 if (!$dbh1) $dbh1=&$dbh;
 if (!$dbh1 or !mysql_ping($dbh1)) {
    db_connect($skiperr);
    $dbh1=&$dbh;
 }
 return $dbh1;
}

function db_disconnect(){
 global $dbh;
 mysql_close($dbh);
}

function db_query($sql, $dbh1=NULL, $skiperr=0){
 $dbh1=db_checkconnect($dbh1, $skiperr);
 $sth=@mysql_query($sql, $dbh1);
 if (!$sth && $skiperr) return;
 catch_db_err($dbh1, $sth, $sql);
 return $sth;
}

function db_array($sql, $dbh1=NULL, $skiperr=0){#array of rows
 $sth=db_query($sql, $dbh1, $skiperr);
 if (!$sth) return;
 $res=array();
 while($row=mysql_fetch_assoc($sth)) $res[]=$row;
 return $res;
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
 if ($_SESSION['sql_sd'] && !$_REQUEST['db']=='*'){//check cache
    $arr=$_SESSION['sql_sd'];
 }else{
   $arr=db_array("show databases");
   $_SESSION['sql_sd']=$arr;
 }

 return @sel($arr,'Database',$sel);
}

function chset_select($sel=''){
 $result='';
 if ($_SESSION['sql_chset']){
    $arr=$_SESSION['sql_chset'];
 }else{
   $arr=db_array("show character set",NULL,1);
   $_SESSION['sql_chset']=$arr;
 }

 return @sel($arr,'Charset',$sel);
}

function sel($arr,$n,$sel=''){
 foreach($arr as $a){
   $b=$a[$n];
   $res.="<option value='$b' ".($sel && $sel==$b?'selected':'').">$b</option>";
 }
 return $res;
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
 $v=$_REQUEST['v'];
 $_SESSION['DB']=$v;

 if ($_REQUEST['rmb']){
    $tm=time()+60*60*24*30;
    setcookie("conn[db]",  $v['db'],$tm);
    setcookie("conn[user]",$v['user'],$tm);
    setcookie("conn[pwd]", $v['pwd'],$tm);
    setcookie("conn[host]",$v['host'],$tm);
    setcookie("conn[port]",$v['port'],$tm);
    setcookie("conn[chset]",$v['chset'],$tm);
 }else{
    setcookie("conn[db]",  FALSE,-1);
    setcookie("conn[user]",FALSE,-1);
    setcookie("conn[pwd]", FALSE,-1);
    setcookie("conn[host]",FALSE,-1);
    setcookie("conn[port]",FALSE,-1);
    setcookie("conn[chset]",FALSE,-1);
 }
}

//during login only - from cookies or use defaults;
function loadcfg(){
 global $DB;

 if( isset($_COOKIE['conn']) ){
    $a=$_COOKIE['conn'];
    $_SESSION['DB']=$_COOKIE['conn'];
 }else{
    $_SESSION['DB']=$DB;
 }
}

//each time - from session to $DB_*
function loadsess(){
 global $DB;

 $DB=$_SESSION['DB'];

 $rdb=$_REQUEST['db'];
 if ($rdb=='*') $rdb='';
 if ($rdb) {
    $DB['db']=$rdb;
 }
}
?>