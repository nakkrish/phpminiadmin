<?php
/*
 PHP Mini MySQL Admin
 (c) 2004-2006 Oleg Savchuk <osa@viakron.com>

 Light standalone PHP script for easy access MySQL databases.
*/

 $ACCESS_PWD=''; #script access password, set if you want to protect script from public access

 #db connection settings
 $DB_DBNAME="demo"; #default DB, optional
 $DB_USER=""; #required
 $DB_PWD=""; #required
 $DB_HOST="";
 $DB_PORT="";

 $DB_DSN="";  #if DB_DSN set - connect to ADO will be used instead HOST/PORT...

//constants
 $VERSION=1.3;
 $MAX_ROWS_PER_PAGE=50; #max number of rows in select per one page
 $is_limited_sql=0;

 session_start();

 if (!$ACCESS_PWD) $_SESSION['is_logged']=true;  #autologin if no password set

//for debug
 ini_set('display_errors',1);
 error_reporting(E_ALL ^ E_NOTICE);

//strip quotes if they set
 if (get_magic_quotes_gpc()){
  $_POST=array_map('kill_magic_quotes',$_POST);
  $_GET=array_map('kill_magic_quotes',$_GET);
  $_COOKIE=array_map('kill_magic_quotes',$_COOKIE);
  $_REQUEST=array_map('kill_magic_quotes',$_REQUEST);
 }

function kill_magic_quotes($value){
 return is_array($value)?array_map('kill_magic_quotes',$value):stripslashes($value);
}

 //get initial values
 if ($_REQUEST['db']) $DB_DBNAME=$_REQUEST['db'];
 $SQLq=trim($_REQUEST['q']);
 $page=$_REQUEST['p']+0;
 if ($_REQUEST['refresh'] && $DB_DBNAME && !$SQLq) $SQLq="show tables";

 if ($_REQUEST['login']){
    if ($_REQUEST['pwd']!=$ACCESS_PWD){
       $err_msg="Invalid password. Try again";
    }else{
       $_SESSION['is_logged']=true;
    }
 }

 if ($_REQUEST['logoff']){
    $_SESSION = array();
    session_destroy();
    header('location: '.$_SERVER['PHP_SELF']);
 }

 if ($_SESSION['is_logged']){
    if ($DB_DBNAME){
       perform_sql($SQLq,$page);
    }else{
       $err_msg="Select DB first";
    }
    print_screen();
 }else{
    print_login();
 }

//**************** functions

function perform_sql($q, $page=0){
 global $dbh, $DB_DBNAME, $out_message, $sqldr, $reccount, $time_all, $MAX_ROWS_PER_PAGE, $is_limited_sql;
 $rc=array("o","e");

 $time_start=microtime_float();
 
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
 
       $sqldr="<table border='0' cellpadding='1' cellspacing='1' width='100%' class='res'>";
       $headers="<tr class='h'>";
       for($i=0;$i<$fields_num;$i++){
          $meta=mysql_fetch_field($sth,$i);
          $fnames[$i]=$meta->name;
          $headers.="<th>$fnames[$i]</th>";
       }
       $headers.="</tr>\n";
       $sqldr.=$headers;
       $swapper=false;
       while($hf=mysql_fetch_assoc($sth)){
         $sqldr.="<tr class='".$rc[$swp=!$swp]."'>";
         for($i=0;$i<$fields_num;$i++){
            $v=$hf[$fnames[$i]];
            if ($is_show_tables && $i==0 && $v){
               $v="<a href=\"?db=$DB_DBNAME&q=show+create+table+$v\">$v</a>";
            }
            if ($is_show_crt) {
               $v="<pre>$v</pre>";
            }
            $sqldr.="<td>$v".(!v?"<br />":'')."</td>";
         }
         $sqldr.="</tr>\n";
       }
       $sqldr.="</table>\n";
    }

 }
 elseif (preg_match("/^update|insert|delete|drop|truncate|alter|create/i",$q)){
    $sth = db_query($q);
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
 $time_all=ceil((microtime_float()-$time_start)*10000)/10000;
 
}

function print_header(){
 global $err_msg,$VERSION,$DB_DBNAME;
?>
<html>
<head>
<style type="text/css">
body, th, td {
 font-family: Arial, Helvetica, sans-serif;
 font-size:80%;
 padding:0px;
 margin:0px;
}
div{
 padding:3px;
}
.inv{
 background-color:#006699;
 color:#FFFFFF;
}
.inv a{
 color:#FFFFFF;
}
table.res tr{
 vertical-align:top;
}
tr.e{
 background-color:#CCCCCC;
}
tr.o{
 background-color:#EEEEEE;
}
tr.h{
 background-color:#9999CC;
}
.err{
 color:#FF3333;
 font-weight:bold;
 text-align:center;
}

</style>

<script type="text/javascript">
function frefresh(){
 var F=document.DF;
 F.refresh.value="1";
 F.submit();
}
function go(p,sql){
 var F=document.DF;
 F.p.value=p;
 if(sql)F.q.value=sql;
 F.submit();
}
</script>

</head>
<body>
<form method="POST" name="DF" action="<?=$_SERVER['PHP_SELF']?>">
<input type="hidden" name="refresh" value="">
<input type="hidden" name="p" value="">

<div class="inv">
<b>phpMiniAdmin <?=$VERSION?></b>
<? if ($_SESSION['is_logged']){ ?>
 | 
Database: <select name="db" onChange="frefresh()">
<option value=''> - select -
<?=get_db_select($DB_DBNAME)?>
</select>
<? if($DB_DBNAME){ ?>
 &#183;<a href="<?=$_SERVER['PHP_SELF']?>?db=<?=$DB_DBNAME?>&q=show+tables">show tables</a>
<? } ?>
 | <a href="?logoff=1">Logoff</a>
<?} ?>
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
<input type=submit name="GoSQL" value="Go" style="width:100px">&nbsp;&nbsp;
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





//******* utilities
function db_connect(){
 global $dbh, $DB_DBNAME, $DB_HOST,$DB_USER,$DB_PWD;

 $dbh=mysql_connect($DB_HOST,$DB_USER,$DB_PWD) or die('Cannot connect to the database because:'.mysql_error());
 if ($DB_DBNAME) mysql_select_db($DB_DBNAME, $dbh) or die('Cannot select db'.mysql_error());

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
 if ($_SESSION['sql_show_databases']){//check cache
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
# $cur_l_num=int($_[0]);     #current page
# $all_s_num=int($_[1]);     #total number of items
# $ITEMS_ON_PAGE=$_[2];
# $page_name_tpl=$_[3];      #page url /ukr/dollar/notes.cgi?page=    for notes.cgi
# $show_all=$_[5];           #print Totals?
function make_List_Navigation($cur_l_num, $all_s_num, $ITEMS_ON_PAGE, $page_name_tpl, $show_all=''){
  if (!$ITEMS_ON_PAGE) $ITEMS_ON_PAGE=10;
  $all_l_num=floor($all_s_num/$ITEMS_ON_PAGE+0.999999);

  $page_name='';
  $result='';
  $list_w=array('Less','More','Back','Next','First','Total');

  $start_l_num=$cur_l_num-2;
  if($start_l_num<0){
    $start_l_num=0;
  }
  if($all_l_num-$start_l_num<5 && $all_l_num>=5){
    $start_l_num=$all_l_num-5;
  }

  $result="";

  if($start_l_num>0){
    $page_name=page_enum_name($start_l_num-1,$page_name_tpl);
    $result.="<a href='$page_name'>$list_w[0]</a>";       
    $result.=" &nbsp;|&nbsp;\n";
  }
  for($p_p=$start_l_num;$p_p<$all_l_num && $p_p<$start_l_num+5;$p_p++){
     $first_s=$p_p*$ITEMS_ON_PAGE+1;
     $last_s=($p_p+1)*$ITEMS_ON_PAGE;
     $page_name=page_enum_name($p_p,$page_name_tpl);
     if($last_s>$all_s_num){
       $last_s=$all_s_num;
     }      
     if($p_p==$cur_l_num){
        $result.="<b>${first_s}..${last_s}</b>";
     }else{
        $result.="<a href='$page_name'>${first_s}..${last_s}</a>";
     }       
     if($p_p+1<$all_l_num){
        $result.=" &nbsp;|&nbsp;\n";
     }
  }
  if($start_l_num+5<$all_l_num){
    $page_name=page_enum_name($start_l_num+5,$page_name_tpl);
    $result.="<a href='$page_name'>$list_w[1]</a>";       
  }
  $result.=" <br>\n";

  if($cur_l_num>0){
    $page_name=page_enum_name($cur_l_num-1,$page_name_tpl);
    $result.="<a href='$page_name'>$list_w[2]</a> &nbsp;|&nbsp; ";
    $page_name=page_enum_name(0,$page_name_tpl);
    $result.="<a href='$page_name'>$list_w[4]</a>";   
  }
  if($cur_l_num>0 && $cur_l_num+1<$all_l_num){
    $result.=" &nbsp;|&nbsp; ";
  }
  if($cur_l_num+1<$all_l_num){
    $page_name=page_enum_name($cur_l_num+1,$page_name_tpl);
    $result.="<a href='$page_name'>$list_w[3]</a>";    
  }    
  if ($show_all) $result.="  <b>($list_w[5] - $all_s_num)</b> ";

  return $result;
}

function page_enum_name($nnnp,$nnnpage=''){
  return str_replace('%p%',$nnnp, $nnnpage);
}

?>