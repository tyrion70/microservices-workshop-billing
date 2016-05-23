<?php
// phpinfo();
header('Content-Type: application/json');
$starttime = microtime();
if ($_GET['testing']=='1') {
    $testing=true;
}
require __DIR__ . '/vendor/autoload.php';

// date_default_timezone_set('Europe/Berlin');

$myname = $_ENV['NAME'];
$consulurl = $_ENV['CONSULURL'];

use phpFastCache\CacheManager;
$cache = CacheManager::Files();


// Instantiate service discovery
//$serviceLookup = new CascadeEnergy\ServiceDiscovery\Consul\ConsulHttp(null,"192.168.1.111:8500");
$serviceLookup = new CascadeEnergy\ServiceDiscovery\Consul\ConsulHttp(null,$consulurl);

include_once 'connection.inc.php';
try {

    $db = new Connection(
        $serviceLookup->getServiceAddress("mysql"),
        'microservicesbilling',
        base64_decode($serviceLookup->getKV('mysql-username')),
        base64_decode($serviceLookup->getKV('mysql-password')));
} catch (Exception $e) {
    return sendError(500, "500", $e->getMessage());
}

$metricurl = $serviceLookup->getServiceURL("metrics");
if ($metricurl == null) {
    throw new Exception('Metric service not found!');
}

// Parse the metric url
$metricurl = parse_url($metricurl);

// Make a connection to statsd
$connection = new \Domnikl\Statsd\Connection\UdpSocket($metricurl['host'], $metricurl['port']);

// Open client for billing service
$statsd = new \Domnikl\Statsd\Client($connection, "billing");

$statsd->increment($myname.".calls");
$statsd->gauge($myname.".calls", '+1');

// Determine what service is requested
$url = explode("/",$_SERVER['REQUEST_URI']);
$headers = getallheaders();
debugLog($headers);
if ($headers['Content-Encoding'] == "gzip") {
    $body = gzdecode(file_get_contents('php://input'));
} else {
    $body = file_get_contents('php://input');
}
debugLog($body);
$body = json_decode($body);

switch($_SERVER['REQUEST_METHOD']) {

    case "GET":
        if (substr($url[1],0,5) == "bills") {
            if (count($url)==3) {
                // We are in the get billing state, show body text
                $ordernum = explode('?',$url[2]);
                $ordernum = filter_var($ordernum[0], FILTER_VALIDATE_INT);
                if (!$ordernum)  {
                    sendError(403, "403", "This is not allowed (incorrect order number)");
                    break;
                }
                getOrder($ordernum);
                break;
            }
        }
        // Accounts mock service!
        if (substr($url[1],0,8) == "accounts") {
            echo '{
"firstName": "Willem",
"lastName": "Dekker",
"street": "Lichtenauerlaan 120",
"city": "Rotterdam",
"state": "Zuid-Holland",
"postCode": "3062 ME",
"email": "willem.dekker@luminis.eu",
"phone": "+31 88 58 64 640",
"country": "Nederland",
"bankAccount": "NLRABO123456789"
}';
            exit;
        }
        sendError(403, "403", "This is not allowed (wrong function)");
        break;
    case "POST":
        if (substr($url[1],0,5) == "bills") {
            if (count($url)==2) {
                // We are in the post order, show body text
                postOrder();
                break;
            } else {
                if (substr($url[2],0,7) == "returns") {
                    if (count($url)==3) {
                        // We are in the return order function, show body text
                        returnOrder();
                        break;
                    }
                }
            }
        }
        sendError(403, "403", "This is not allowed (wrong function)");
        break;
    default:
        sendError(403, "403", "This is not allowed (wrong method)");
}

function postOrder() {
    global $myname, $statsd, $body, $serviceLookup, $db, $cache, $testing;

    // getaccounts with user
    $accounturl = $serviceLookup->getServiceURL("accounts");
    $url = $accounturl."/accounts/".$body->user;
    $key = "accounts+".$body->user;

    if ($accounturl == null) {
        // No accounts service store order as processing
        // try cache
        $accountinfo = $cache->get($key);
        if (is_null($accountinfo)) {
            // No cached account info, return error
            return sendError(503, "503", "No account information available");
        }
    } else {
        // Get account information and store order
        $accountinfo = @file_get_contents($url);
        if ($accountinfo===false) {
            // Not able to get live accountinfo, try cache
            $accountinfo = $cache->get($key);
            if (is_null($accountinfo)) {
                // No cached account info, return error
                // Insert data into database
                $query = "insert into orders (ordernumber, state, user, price)
                             VALUES (".$body->orderNumber.",'processing','".$body->user."','".$body->price."')";
                try {
                    $i=1;
                    if (!$testing) $db->Query($query);
                } catch (Exception $e) {
                    return sendError(500, "500", $e->getMessage());

                }
                return sendError(503, "503", "No account information available");
            }
        } else {
            // Write accountinfo to Cache in 10 minutes with same keyword
            $cache->set($key, json_decode($accountinfo), 600);
        }
        $accountinfo = json_decode($accountinfo);
    }

    // Insert data into database
    $query = "insert into orders (ordernumber, state, user, name, phone, email, street, city, postcode, price, bankaccount)
              VALUES (".$body->orderNumber.",'processing','".$body->user."','".$accountinfo->firstName." ".$accountinfo->lastName."',
                '".$accountinfo->phone."','".$accountinfo->email."','".$accountinfo->street."','".$accountinfo->city."',
                '".$accountinfo->postCode."','".$body->price."','".$accountinfo->bankAccount."')";
    try {
        $i=1;
        if (!$testing) $db->Query($query);
    } catch (Exception $e) {
        return sendError(500, "500", $e->getMessage());

    }
    $response["orderNumber"] = $body->orderNumber;
    $response["state"] = 'processing';

    echo json_encode($response);

    // call recommendations ???

    $statsd->increment($myname.".postorder");
    $statsd->gauge($myname.".postorder", '+1');
}

function returnOrder() {
    global $myname, $statsd, $body, $db, $testing;

    // Pseudo
    // get id from body
    $orderid = filter_var($body->orderNumber, FILTER_VALIDATE_INT);
    if (!$orderid)  {
        sendError(403, "403", "This is not allowed (incorrect or missing order number)");
    } else {
        $response = array();
        $response["orderNumber"] = $orderid;

        $db->Query("select * from orders where ordernumber = $orderid");
        $row = $db->Fetch();
        if ($row) {
            // only if order state is paid can we return it!!
            if ($row['state']=="paid") {
                // update order line
                $query = "update orders set state='returned' where ordernumber = $orderid";
                if (!$testing) $db->Query($query);

                $response["state"] = 'returned';
                echo json_encode($response);
            } else {
                sendError(403, "403", "Order can't be returned (state == '".$row['state']."')");
            }
        } else {
            sendError(404, "404", "Order not found");
        }
    }
    // return status
    $statsd->increment($myname.".returnorder");
    $statsd->gauge($myname.".returnorder", '+1');
}

function getOrder($orderid) {
    global $myname, $statsd, $body, $db, $testing;

    $statsd->increment($myname.".getorder");
    $statsd->gauge($myname.".getorder", '+1');

    $response = array();
    $response["orderNumber"] = $orderid;

    $db->Query("select * from orders where ordernumber = $orderid");
    $row = $db->Fetch();
    if ($row) {
        $response["state"] = $row['state'];
        $response["contact"]["name"] = $row['name'];
        $response["contact"]["phone"] = $row['phone'];
        $response["contact"]["email"] = $row['email'];
        $response["address"]["street"] = $row['street'];
        $response["address"]["city"] = $row['city'];
        $response["address"]["postCode"] = $row['postcode'];
        echo json_encode($response);
    } else {
        sendError(404, "404", "Order not found");
    }
}

function sendError($httpresponse, $code, $description) {
    global $myname, $statsd;

    // sendError("403", "This is not allowed");
    $statsd->increment($myname.".".$httpresponse);
    $statsd->gauge($myname.".".$httpresponse, '+1');
    http_response_code($httpresponse);
    $error = array();
    $error["code"] = $code;
    $error["description"] = $description;
    echo json_encode($error);
}

function debugLog($log) {
    $handle = fopen('debug.log',"a+");
    if (is_array($log) or is_object($log)) {
        fwrite($handle, date("Y-m-d H:i")." ".var_export($log, true)."\n");
    } else {
        fwrite($handle, date("Y-m-d H:i")." ".$log."\n");
    }
    fclose($handle);
}

// phpinfo();
/*
echo "Count: " . count($url);
echo $body;
print_r($_SERVER);
print_r($_ENV);
*/
$endtime = microtime();
$statsd->timing($myname.".loadtime", $endtime - $starttime);

?>
