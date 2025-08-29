<?php
function getVerifactiConfig($key=''){
	$data = json_decode(get_option(VERIFACTI_MODULE_NAME.'_setting'),TRUE);
	if(!empty($key)){
		return $data[$key] ?? false;
	}
	return $data;
}
function isVerifactiEnable(){
	return getVerifactiConfig('enable') == 'yes' ? true : false;
}
if(!function_exists('atCurlRequest')){
	function atCurlRequest($url, $method = "get", $request_fields = array(), $headers = [], $set_user_agent=false) {
	    $ch = curl_init();
	    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
	    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
	    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
	    curl_setopt($ch, CURLOPT_MAXREDIRS, 10);

	    if (!empty($headers)) {
	        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
	    }

	    if (!empty($userpwd)) {
	        curl_setopt($ch, CURLOPT_USERPWD, $userpwd);
	    }
	    if($set_user_agent){
	        
	        $agents = array(
	            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.7; rv:7.0.1) Gecko/20100101 Firefox/7.0.1',
	            'Mozilla/5.0 (X11; U; Linux i686; en-US; rv:1.9.1.9) Gecko/20100508 SeaMonkey/2.0.4',
	            'Mozilla/5.0 (Windows; U; MSIE 7.0; Windows NT 6.0; en-US)',
	            'Mozilla/5.0 (Macintosh; U; Intel Mac OS X 10_6_7; da-dk) AppleWebKit/533.21.1 (KHTML, like Gecko) Version/5.0.5 Safari/533.21.1'
	         
	        );
	        curl_setopt($ch,CURLOPT_USERAGENT,$agents[array_rand($agents)]);
	    }
	    $method = strtoupper($method);
	    if ($method == "POST") {
	        curl_setopt($ch, CURLOPT_POST, true);
	        if(is_array($request_fields)){
	        	$request_fields = http_build_query($request_fields);
	        }
	        curl_setopt($ch, CURLOPT_POSTFIELDS, $request_fields);
	    } else if ($method == "GET") {
		    curl_setopt($ch, CURLOPT_HTTPGET, true);
	    } else {
	        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
	        if(!empty($request_fields)){
		        if(is_array($request_fields)){
		        	$request_fields = http_build_query($request_fields);
		        }
		        curl_setopt($ch, CURLOPT_POSTFIELDS, $request_fields);
	        }
	    }
	    curl_setopt($ch, CURLOPT_URL, $url);
	    $result = curl_exec($ch);
	    $curl_err = curl_error($ch);
	    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	    $status = true;
	    if ($curl_err) {
	    	$status = false;
	        $result = $curl_err;
	    }
	    $result_array = json_decode($result,true);
	    if(isset($result_array['errors'])){
	    	$status = false;
	    }
	    curl_close($ch);
	    $return = [
	        'status'=>$status,
	        'http_code'=>$http_code,
	        'result'=>$result_array,
	        'url'=>$url
	    ];
	    return $return;
	}
}
if(!function_exists('generateVerifactiQr')){
	function generateVerifactiQr($item){
		$base64_str = $item->qr_image_base64;
		if(!empty($base64_str)){
			return <<<HTML
			<div>
				<img src="data:image/jpeg;base64,{$base64_str}" height="100" width="100" />
			</div>
			HTML;
		}
		return '';
	}
}
if(!function_exists('_print_r')){
	function _print_r($str=''){
		echo "<pre>";
		print_r($str);
		echo "</pre>";
	}
}
if(!function_exists('verifacti_normalize_nif')){
	/**
	* Normaliza el NIF emisor para Verifacti: elimina prefijo de país (ES, EU, etc.) y deja solo el identificador fiscal interno.
	 * Mantiene letras iniciales finales (p.ej. CIF español con letra).
	 * Ej: ESB75777847 -> B75777847
	 */
	function verifacti_normalize_nif($raw){
		$raw = trim((string)$raw);
		if($raw === '') return $raw;
		// Detectar prefijo país de 2 letras al inicio seguido de alfanumérico
		if(preg_match('/^[A-Z]{2}[A-Z0-9]/i',$raw)){
			// Lista común de prefijos (podríamos ampliar); si coincide, recortamos 2 chars
			$prefix = strtoupper(substr($raw,0,2));
			if(preg_match('/^(ES|EU|FR|DE|IT|PT|NL|BE|DK|SE|NO|FI|IE|GB|AT|PL|CZ|SK|HU|RO|BG|HR|SI|EE|LV|LT)$/',$prefix)){
				$raw = substr($raw,2);
			}
		}
		return strtoupper($raw);
	}
}