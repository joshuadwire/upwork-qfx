<?php
include('vendor/autoload.php');
include('config.php');

$saved_data = file_exists('saved_data.json')?json_decode(file_get_contents('saved_data.json'), true):null;
if(!$saved_data)$saved_data = array(
	'access_token'=>null,
	'access_secret'=>null,
	'request_token'=>null,
	'request_secret'=>null,
);

$config = new \Upwork\API\Config(
	array(
		'consumerKey'       => UPWORK_CONSUMER_KEY,
		'consumerSecret'    => UPWORK_CONSUMER_SECRET,
		'accessToken'       => $saved_data['access_token'],       // got access token
		'accessSecret'      => $saved_data['access_secret'],      // got access secret
		'requestToken'      => $saved_data['request_token'],      // got request token
		'requestSecret'     => $saved_data['request_secret'],     // got request secret
		'verifier'          => !empty($_GET['oauth_verifier'])?$_GET['oauth_verifier']:null,         // got oauth verifier after authorization
		'mode'              => 'web',                           // can be 'nonweb' for console apps (default), and 'web' for web-based apps
	)
);
$api_client = new \Upwork\API\Client($config);

if (empty($saved_data['request_token']) && empty($saved_data['access_token'])) {
	// We need to get and save the request token. It will be used again
	// after the redirect from the Upwork site
	$requestTokenInfo = $api_client->getRequestToken();
	$saved_data['request_token']  = $requestTokenInfo['oauth_token'];
	$saved_data['request_secret'] = $requestTokenInfo['oauth_token_secret'];
	save_data($saved_data);

	// request authorization
	$api_client->auth();
} elseif (empty($saved_data['access_token'])) {
	// the callback request should be pointed to this script as well as
	// the request access token after the callback
	$accessTokenInfo = $api_client->auth();
	$saved_data['access_token']   = $accessTokenInfo['access_token'];
	$saved_data['access_secret']  = $accessTokenInfo['access_secret'];
	save_data($saved_data);
}

// if authenticated
if ($saved_data['access_token']) {
	// clean up session data
	$saved_data['request_token'] = null;
	$saved_data['request_secret'] = null;
	save_data($saved_data);

	if(empty($saved_data['freelancer_ref'])){
		// Gets info of the authenticated user
		$auth = new \Upwork\API\Routers\Auth($api_client);
		$info = $auth->getUserInfo();
		
		$saved_data['freelancer_ref'] = $info->info->ref;
		save_data($saved_data);
	}

	if(!empty($saved_data['freelancer_ref'])){
		$transactions = get_transactions($saved_data['freelancer_ref'], $api_client, $server_time);
		create_qfx($saved_data['freelancer_ref'], $transactions, $server_time);
	}
	else{
		exit('Could not get user data');
	}
}

function save_data($data){
	file_put_contents('saved_data.json', json_encode($data));
}

function get_transactions($freelancer_ref, $api_client, &$server_time=null){
	$keys = array('date', 'date_due', 'reference', 'type', 'subtype', 'description', 'buyer_company_name', 'assignment_name', 'amount');

	if(!defined('BEGIN_DATE'))define('BEGIN_DATE', strtotime('-90 days'));
	if(!defined('END_DATE'))define('END_DATE', strtotime('+15 days'));

	$begin_date = date('Y-m-d', BEGIN_DATE);
	$end_date = date('Y-m-d', END_DATE);

	$params = array(
		"tq" => "SELECT ".implode(',',$keys)." WHERE date_due >= '$begin_date' AND date_due <= '".$end_date."'"
	);

	$accounts = new \Upwork\API\Routers\Reports\Finance\Accounts($api_client);
	$data = $accounts->getOwned($freelancer_ref, $params);

	$server_time = $data->server_time;

	$transactions = $data->table->rows;
	$transactions = array_map(function($el) use($keys){
		return array_combine($keys, array_map(function($el) use($keys){
			return $el->v;
		}, $el->c));
	}, $transactions);	

	return $transactions;
}

function create_qfx($freelancer_ref, $transactions, &$server_time=null){
	if(empty($transactions)){
		exit("No Data");
	}
	
	// Sort transactions
	uasort($transactions, function($a, $b){
		// Sort by date
		$a_date = strtotime($a['date_due']);
		$b_date = strtotime($b['date_due']);
		if($a_date < $b_date)return -1;
		if($a_date > $b_date)return 1;
		
		// Put transfers last
		if($a['type'] == 'APPayment' && $a['subtype'] == 'Withdrawal')return 1;
		if($b['type'] == 'APPayment' && $b['subtype'] == 'Withdrawal')return -1;
		
		// Alphabetically by client name
		$name_compare = strcasecmp($a['buyer_company_name'], $b['buyer_company_name']);		
		if($name_compare !== 0)return $name_compare;
		
		// Put the service fee after the associated earning
		if($a['type'] == 'APAdjustment' && $a['subtype'] == 'Service Fee')return 1;
		if($b['type'] == 'APAdjustment' && $b['subtype'] == 'Service Fee')return -1;
		
		return 0;
	});
	
	header('Content-Type: application/qif');
	header('Content-Disposition: attachment; filename="upwork-'.date('Y-m-d').'.qfx"');

	$begin_date = date('Ymd', BEGIN_DATE);
	$end_date = date('Ymd', END_DATE);
	$curdate = date('Ymd', $server_time);

	echo "OFXHEADER:100
DATA:OFXSGML
VERSION:102
SECURITY:NONE
ENCODING:USASCII
CHARSET:1252
COMPRESSION:NONE
<OFX>
	<SIGNONMSGSRSV1>
		<SONRS>
			<STATUS>
				<CODE>0</CODE>
				<SEVERITY>INFO</SEVERITY>
			</STATUS>
			<DTSERVER>$curdate</DTSERVER>
			<LANGUAGE>ENG</LANGUAGE>
			<FI>
				<ORG>Upwork</ORG>
				<FID>62160</FID>
			</FI>
			<INTU.BID>62160</INTU.BID>
			<INTU.USERID>$freelancer_ref</INTU.USERID>
		</SONRS>
	</SIGNONMSGSRSV1>
	<BANKMSGSRSV1>
		<STMTTRNRS>
			<TRNUID>0</TRNUID>
			<STATUS>
				<CODE>0</CODE>
				<SEVERITY>info</SEVERITY>
			</STATUS>
			<STMTRS>
				<CURDEF>USD</CURDEF>
				<BANKACCTFROM>
					<BANKID>1234</BANKID>
					<ACCTID>$freelancer_ref</ACCTID>
					<ACCTTYPE>CHECKING</ACCTTYPE>
				</BANKACCTFROM>
				<BANKTRANLIST>
					<DTSTART>$begin_date</DTSTART>
					<DTEND>$end_date</DTEND>";

	$currbal = 0;
	$availbal = 0;

	foreach($transactions as $transaction){
		$transaction['date'] = date("Ymd", strtotime($transaction['date']));
		
		$payee = $transaction['buyer_company_name'];

		$trntype = '';
		$sic = '';
		$optel = '';
		
		$currbal +=  $transaction['amount'];
		if(strtotime($transaction['date_due']) <= time())$availbal +=  $transaction['amount'];

		if($transaction['type'] == 'APInvoice'){
			$sic = SERVICE_SIC_CODE;
			$transaction['description'] = $transaction['subtype'] .' - '.$transaction['assignment_name'];
		}
		else if($transaction['type'] == 'APAdjustment' && $transaction['subtype'] == 'Service Fee'){
			$trntype = 'SRVCHG';
			$payee = 'Upwork';
			$sic = 7363;
			$transaction['description'] = strtok($transaction['description'], "\n") .' ('.$transaction['buyer_company_name'].': '.$transaction['assignment_name'].')';
		}
		else if($transaction['type'] == 'APPayment' && $transaction['subtype'] == 'Withdrawal'){
			$trntype = "XFER";
			
			// A transfer always epmties the account
			$currbal = 0;
			$availbal = 0;
 		}

		if(empty($trntype)){
			$trntype = $transaction['amount'] > 0 ? "CREDIT" : "DEBIT";
		}

		$optel .= $payee?"<NAME>$payee</NAME>":'';
		$optel .= $sic?"<SIC>$sic</SIC>":'';

		echo "
				<STMTTRN>
					<TRNTYPE>$trntype</TRNTYPE>
					<DTPOSTED>{$transaction['date_due']}</DTPOSTED>
					<DTAVAIL>{$transaction['date_due']}</DTAVAIL>
					<TRNAMT>{$transaction['amount']}</TRNAMT>
					<FITID>{$transaction['reference']}</FITID>
					<MEMO>{$transaction['description']}</MEMO>
					$optel
				</STMTTRN>
				";
	}
	echo "
				</BANKTRANLIST>
				<LEDGERBAL>
					<BALAMT>$currbal</BALAMT>
					<DTASOF>$curdate</DTASOF>
				</LEDGERBAL>
				<AVAILBAL>
					<BALAMT>$availbal</BALAMT>
					<DTASOF>$curdate</DTASOF>
				</AVAILBAL>
			</STMTRS>
		</STMTTRNRS>
	</BANKMSGSRSV1>
</OFX>";
}