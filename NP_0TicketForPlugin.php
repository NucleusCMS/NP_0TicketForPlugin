<?php 
/*****************************************
*    NP_0TicketForPlugin.php             *
*                    Nucleus(JP) team    *
* License : GPL                          *
* This program is free software; you can *
* redistribute it and/or modify it under *
* the terms of the GNU General Public    *
* License as published by the Free       *
* Software Foundation; either version    *
* 2 of the License, or (at your option)  *
* any later version.                     *
*****************************************/
 
/*
 * 0.11a    The initial version
 * 0.13a    Bug (addition of ticket to outside page) fixed.
 * 1.0      The first release.
 * 1.1      Support some environment that cannot add the ticket by JavaScript.
 *          Support IIS.
 * 1.1.1    Problem on the redirection when ticket is expired fixed.
 * 1.2b     Following the event_PostAuthentication added.
 *          Now this plugin skips the checking when it is done by plugin itself.
 * 1.2.5b   Added the routine to check if the plugin is installed.
 * 1.2.6b   Bug fix (include PLUGINADMIN.php, language.php etc).
 * 1.2.7b   Supports the servers that do not provide PATH_TRANSLATED
 * 1.2.8b   Return nothing when the plugin is not installed
 * 1.2.8.1a Added "file_exists($p_translated)" check.
 *          Skips ticket-checking routine if not "adminarea/index.php".
 *          Exits if using "adminarea/index.php" and not logged in.
 */
 
class NP_0TicketForPlugin extends NucleusPlugin { 
	function getName() { return 'NP_0TicketForPlugin'; }
	function getAuthor() { return 'Nucleus(JP) team'; }
	function getMinNucleusVersion() { return 250; }
	function getURL() { return 'http://japan.nucleuscms.org/bb/'; }
	function getVersion() { return '1.2.8.1a'; }
	function getDescription() { return $this->getName().' plugin'; } 
	function supportsFeature($what) { return (int)($what=='SqlTablePrefix'); }
	function getEventList() { return array('PostAuthentication','AdminPrePageHead','AdminPrePageFoot'); }
	
	var $ticket;
	var $checkdone;
	function init(){
		$this->ticket=false;
		$this->checkdone=false;
	}
	
	var $plugins;
	function event_PostAuthentication(&$data){
		global $CONF,$DIR_PLUGINS;
		/* Check if using plugins's php file. */
		if ($p_translated=serverVar('PATH_TRANSLATED')) {
			if (!file_exists($p_translated)) $p_translated='';
		}
		if (!$p_translated) {
			$p_translated=serverVar('SCRIPT_FILENAME');
			if (!file_exists($p_translated)) {
				header("HTTP/1.0 404 Not Found");
				exit('');
			}
		}
		$p_translated=str_replace('\\','/',$p_translated);
		$d_plugins=str_replace('\\','/',$DIR_PLUGINS);
		if (strpos($p_translated,$d_plugins)!==0) return;// This isn't plugin php file.
		
		/* Solve the plugin php file or admin directory */
		$phppath=substr($p_translated,strlen($d_plugins));
		$phppath=preg_replace('!^/!','',$phppath);// Remove the first "/" if exists.
		$path=preg_replace('/^NP_([.]*)\.php$/','$1',$phppath); // Remove the first "NP_" and the last ".php" if exists.
		$path=preg_replace('!^([^/]*)/(.*)$!','$1',$path); // Remove the "/" and beyond.
		
		/* Solve the plugin name. */
		$this->plugins=array();
		$query='SELECT pfile FROM '.sql_table('plugin');
		$res=sql_query($query);
		while($row=mysql_fetch_row($res)) {
			$name=substr($row[0],3);
			$this->plugins[strtolower($name)]=$name;
		}
		mysql_free_result($res);
		if ($this->plugins[$path]) $plugin_name=$this->plugins[$path];
		else if (array_key_exists($path,$this->plugins)) $plugin_name=$path;
		else {
			header("HTTP/1.0 404 Not Found");
			exit('');
		}
		
		/* Return if not index.php */
		if ( $phppath!=strtolower($plugin_name).'/'
			&& $phppath!=strtolower($plugin_name).'/index.php' ) return;
		
		/* Exit if not logged in. */
		if (!$data['loggedIn']) exit("You aren't logged in.");
		
		if ($this->checkdone) return; // Ticket is already checked.
		$this->_check($plugin_name);
	}
	
	function event_AdminPrePageHead(&$data){
		if ($this->checkdone) return;
		/* Check if this is PluginAdmin */
		if (strpos($data['action'],'plugin_')!==0) return;
		if ($data['action']=="plugin_0TicketForPlugin") return;
		
		$this->_check(preg_replace('/^plugin_/','',$data['action']));
	}
	
	function _check($plugin_name){
		global $manager,$DIR_LIBS,$DIR_LANG,$HTTP_GET_VARS,$HTTP_POST_VARS;
		$this->checkdone=true;
		
		/* Check if this feature is needed (ie, if "$manager->checkTicket()" is not included in the script). */
		if (!($p_translated=serverVar('PATH_TRANSLATED'))) $p_translated=serverVar('SCRIPT_FILENAME');
		if ($file=@file($p_translated)) {
			$prevline='';
			foreach($file as $line) {
				if (preg_match('/[\$]manager([\s]*)[\-]>([\s]*)checkTicket([\s]*)[\(]/i',$prevline.$line)) return;
				$prevline=$line;
			}
		}
		
		/* Show a form if not valid ticket */
		if ( ( strstr(serverVar('REQUEST_URI'),'?') || serverVar('QUERY_STRING')
				|| strtoupper(serverVar('REQUEST_METHOD'))=='POST' )
					&& (!$manager->checkTicket()) ){
 
			if (!class_exists('PluginAdmin')) {
				$language = getLanguageName();
				include($DIR_LANG . ereg_replace( '[\\|/]', '', $language) . '.php');
				include($DIR_LIBS . 'PLUGINADMIN.php');
			}
			$oPluginAdmin = new PluginAdmin('0TicketForPlugin');
			$oPluginAdmin->start();
			echo '<p>' . _ERROR_BADTICKET . "</p>\n";
			
			/* Show the form to confirm action */
			// PHP 4.0.x support
			$get=  (isset($_GET))  ? $_GET  : $HTTP_GET_VARS;
			$post= (isset($_POST)) ? $_POST : $HTTP_POST_VARS;
			// Resolve URI and QUERY_STRING
			if ($uri=serverVar('REQUEST_URI')) {
				list($uri,$qstring)=explode('?',$uri);
			} else {
				if ( !($uri=serverVar('PHP_SELF')) ) $uri=serverVar('SCRIPT_NAME');
				$qstring=serverVar('QUERY_STRING');
			}
			if ($qstring) $qstring='?'.$qstring;
			echo '<p>'._SETTINGS_UPDATE.' : '._QMENU_PLUGINS.' <span style="color:red;">'.
				htmlspecialchars($plugin_name)."</span> ?</p>\n";
			switch(strtoupper(serverVar('REQUEST_METHOD'))){
			case 'POST':
				echo '<form method="POST" action="'.htmlspecialchars($uri.$qstring).'">';
				$manager->addTicketHidden();
				$this->_addInputTags($post);
				break;
			case 'GET':
				echo '<form method="GET" action="'.htmlspecialchars($uri).'">';
				$manager->addTicketHidden();
				$this->_addInputTags($get);
			default:
				break;
			}
			echo '<input type="submit" value="'._YES.'" />&nbsp;&nbsp;&nbsp;&nbsp;';
			echo '<input type="button" value="'._NO.'" onclick="history.back(); return false;" />';
			echo "</form>\n";
			
			$oPluginAdmin->end();
			exit;
		}
		
		/* Create new ticket */
		$ticket=$manager->addTicketToUrl('');
		$this->ticket=substr($ticket,strpos($ticket,'ticket=')+7);
	}
	function _addInputTags(&$keys,$prefix=''){
		foreach($keys as $key=>$value){
			if ($prefix) $key=$prefix.'['.$key.']';
			if (is_array($value)) $this->_addInputTags($value,$key);
			else {
				if (get_magic_quotes_gpc()) $value=stripslashes($value);
				if ($key=='ticket') continue;
				echo '<input type="hidden" name="'.htmlspecialchars($key).
					'" value="'.htmlspecialchars($value).'" />'."\n";
			}
		}
	}
	function event_AdminPrePageFoot(&$data){
		global $CONF;
		if (!($ticket=$this->ticket)) {
			if ($this->checkdone) echo "\n<!--NP_0TicketForPlugin skipped-->\n";
			return;
		}
 
?><script type="text/javascript">
/*<![CDATA[*/
/* Add tickets for available links (outside blog excluded) */
for (i=0;document.links[i];i++){
  if (document.links[i].href.indexOf('<?php echo $CONF['PluginURL']; ?>',0)<0
    && !(document.links[i].href.indexOf('//',0)<0)) continue;
  if ((j=document.links[i].href.indexOf('?',0))<0) continue;
  if (document.links[i].href.indexOf('ticket=',j)>=0) continue;
  document.links[i].href=document.links[i].href.substring(0,j+1)+'ticket=<?php echo $ticket; ?>&'+document.links[i].href.substring(j+1);
}
/* Add tickets for forms (outside blog excluded) */
for (i=0;document.forms[i];i++){
  /* check if ticket is already used */
  for (j=0;document.forms[i].elements[j];j++) {
    if (document.forms[i].elements[j].name=='ticket') {
      j=-1;
      break;
    }
  }
  if (j==-1) continue;
 
  /* check if the modification works */
  try{document.forms[i].innerHTML+='';}catch(e){
    /* Modificaion falied: this sometime happens on IE */
    if (!document.forms[i].action.name && document.forms[i].method.toUpperCase()=="POST") {
      /* <input name="action"/> is not used for POST method*/
      if (document.forms[i].action.indexOf('<?php echo $CONF['PluginURL']; ?>',0)<0
        && !(document.forms[i].action.indexOf('//',0)<0)) continue;
      if (0<(j=document.forms[i].action.indexOf('?',0))) if (0<document.forms[i].action.indexOf('ticket=',j)) continue;
      if (j<0) document.forms[i].action+='?'+'ticket=<?php echo $ticket; ?>';
      else document.forms[i].action+='&'+'ticket=<?php echo $ticket; ?>';
      continue;
    }
    document.write('<p><b>Error occured druing automatic addition of tickets.</b></p>');
    j=document.forms[i].outerHTML;
    while (j!=j.replace('<','&lt;')) j=j.replace('<','&lt;');
    document.write('<p>'+j+'</p>');
    continue;
  }
  /* check the action paramer in form tag */
  /* note that <input name="action"/> may be used here */
  j=document.forms[i].innerHTML;
  document.forms[i].innerHTML='';
  if ((document.forms[i].action+'').indexOf('<?php echo $CONF['PluginURL']; ?>',0)<0
      && !((document.forms[i].action+'').indexOf('//',0)<0)) {
    document.forms[i].innerHTML=j;
    continue;
  }
  /* add ticket */
  document.forms[i].innerHTML=j+'<input type="hidden" name="ticket" value="<?php echo $ticket; ?>"/>';
}
/*]]>*/
</script><?php
 
	}
}
?>
