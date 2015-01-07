<?php
if(!defined('_STORAGEMADEEASY_API_URL'))		define('_STORAGEMADEEASY_API_URL','http://'. get_option('storagemadeeasy_server') .'/api/');

/**
* Takes REST command, process request and return array created from returned xml document
* 
**/
function processRequest($request,$debug=0,$data=array(),$files=array()){
	$result=array('',array());
	$http=new http_class;
	//$http->timeout=0;
	//$http->data_timeout=0;
	$http->debug=($debug==2)?1:0;
	$http->html_debug=1;
	$http->follow_redirect=1;

	if(strpos($request, 'http://')!==false){
		$url=$request;
	}else{
		$url=_STORAGEMADEEASY_API_URL.$request;
	}


//	echo $url.'<br>';
	if($debug>0){
		echo $url.'<br>';
		if($debug>1)
			exit;
	}
	//exit;
	//return;
	$error=$http->GetRequestArguments($url,$arguments);
	$arguments["RequestMethod"]="POST";
	$arguments["PostValues"]=array(
		
	);
	foreach (array_keys($data) as $d)
		$arguments["PostValues"][$d]=$data[$d];

	if(count($files)>0 ){
		$arguments["PostFiles"]=array();
		if(strpos($request, 'http://')===false) $arguments["PostValues"]["MAX_FILE_SIZE"]="10000000";
		foreach(array_keys($files) as $f)
			$arguments["PostFiles"][$f]=array('FileName'=>$files[$f],"Content-Type"=>"automatic/name");
	}
		//print_r($arguments["PostFiles"]);
	//$arguments["Referer"]="http://www.alltheweb.com/";
	$result[0]=$http->Open($arguments);

	if($result[0]=="")
	{
		$result[0]=$http->SendRequest($arguments);

		if($result[0]=="")
		{
			$result[0]=$http->ReadReplyHeaders($headers);

			if($result[0]=="")
			{
				$result[0]=$http->ReadReplyBody($body,1000000);
				if($debug>0)
					echo $body;
				$x=new XmlToArray($body);
				$a=$x->createArray();
				$a=$a['response'];
				if($a['status']!='ok')
					$result[0]=$a['statusmessage'];
				if($debug>0){
					print_r($a);
					exit;
				}
				$result[1]=$a;
				
			}
		}
		$http->Close();
	}
	return $result;
}
/**
* Encode arguments with base64 encoding and join with comma delimiter
**/
function encodeArgs($args){
	$a=array();
	foreach ($args as $ar){
		$a[]=base64_encode($ar);
	}
	return join(',',$a);
}

?>