<?php
// phpinfo();
$starttime = microtime();
require __DIR__ . '/vendor/autoload.php';

$myname = $_ENV['NAME'];
$consulurl = $_ENV['CONSULURL'];

use phpFastCache\CacheManager;
$cache = CacheManager::Files();

include_once 'connection.inc.php';
$db = new Connection();

// Instantiate service discovery
//$serviceLookup = new CascadeEnergy\ServiceDiscovery\Consul\ConsulHttp(null,"192.168.1.111:8500");
$serviceLookup = new CascadeEnergy\ServiceDiscovery\Consul\ConsulHttp(null,$consulurl);

$metricurl = $serviceLookup->getServiceAddress("metric");
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
$body = file_get_contents('php://input');
$body = json_decode($body);

switch($_SERVER['REQUEST_METHOD']) {

    case "GET":
        if ($url[1] == "bills") {
            if (count($url)==3) {
                // We are in the get billing state, show body text
                $ordernum = filter_var($url[2], FILTER_VALIDATE_INT);
                if (!$ordernum)  {
                    sendError(403, "403", "This is not allowed (incorrect order number)");
                    break;
                }
                getOrder($ordernum);
                break;
            }
        }
        sendError(403, "403", "This is not allowed (wrong function)");
        break;
    case "POST":
        if ($url[1] == "bills") {
            if (count($url)==2) {
                // We are in the post order, show body text
                postOrder();
                break;
            } else {
                if ($url[2] == "returns") {
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
    global $myname, $statsd, $body, $serviceLookup, $cache;

/*
        {
        user: “Willem Dekker”,
        orderNumber: 42
        price: 144.12
        }
 */
    // getaccounts with user
    $accounturl = $serviceLookup->getServiceAddress("accounts");
    $url = $accounturl."/accounts?user=".$body->name;
    $key = "accounts+".$body->user;

    $response["key"] = $key;
    $response["url"] = $url;

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
        $accountinfo = file_get_contents($url);
        if ($accountinfo===false) {
            // Not able to get live accountinfo, try cache
            $accountinfo = $cache->get("accounts+" . $body->user);
            if (is_null($accountinfo)) {
                // No cached account info, return error
                return sendError(503, "503", "No account information available");
            }
        } else {
            // Write accountinfo to Cache in 10 minutes with same keyword
            $cache->set($key, json_decode($accountinfo), 600);
        }
        $accountinfo = json_decode($accountinfo);
    }

    // Insert data into database
    //
    $query = "insert into order SET
                state='processing',
                user='".$body->user."',
                name='".$accountinfo->firstName." ".$accountinfo->lastName."',
                phone='".$accountinfo->phone."',
                email='".$accountinfo->email."',
                street='".$accountinfo->street."',
                city='".$accountinfo->city."',
                postcode='".$accountinfo->postCode."'";
    $ordernumber = $db->QueryReturn($query);
    $response["orderNumber"] = $ordernumber;
    $response["state"] = 'processing';

    echo json_encode($response);

    // call recomendations ???

    $statsd->increment($myname.".postorder");
    $statsd->gauge($myname.".postorder", '+1');
}

function returnOrder() {
    global $myname, $statsd, $body, $db;

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
                echo $query;
                $db->Query($query);

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
    global $myname, $statsd, $body, $db;

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
