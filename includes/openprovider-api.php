<?php

class OP_API_Exception extends Exception {}

class OP_API
{
  protected $url = null;
  protected $error = null;
  protected $timeout = null;
  protected $debug = null;
  static public $encoding = 'UTF-8';

  public function __construct ($url = null, $timeout = 1000)
  {
    $this->url = $url;
    $this->timeout = $timeout;
    // $this->fetchDomains();
    add_action( 'init', [ $this, 'fetchDomains' ] );

  }
  public function setDebug ($v)
  {
    $this->debug = $v;
    return $this;
  }
  public function processRawReply (OP_Request $r) {
    if ($this->debug) {
      echo $r->getRaw() . "\n";
    }
    $msg = $r->getRaw();
    $str = $this->_send($msg);
    if (!$str) {
      throw new OP_API_Exception("Bad reply", 4004);
    }
    if ($this->debug) {
      echo $str . "\n";
    }
    return $str;
  }
  public function process (OP_Request $r) {
    if ($this->debug) {
      echo $r->getRaw() . "\n";
    }

    $msg = $r->getRaw();
    $str = $this->_send($msg);
    if (!$str) {
      throw new OP_API_Exception("Bad reply", 4004);
    }
    if ($this->debug) {
      echo $str . "\n";
    }
    return new OP_Reply($str);
  }
  /**
  * Check if xml was created successfully with $str
  * @param $str string
  * @return boolean
  */
  static function checkCreateXml($str)
  {
    $dom = new DOMDocument;
    $dom->encoding = 'utf-8';

    $textNode = $dom->createTextNode($str);

    if (!$textNode) {
      return false;
    }

    $element = $dom->createElement('element');
    $element->appendChild($textNode);

    if (!$element) {
      return false;
    }

    $dom->appendChild($element);

    $xml = $dom->saveXML();

    return !empty($xml);
  }
  static function encode ($str)
  {
    $ret = @htmlentities($str, null, OP_API::$encoding);
    // Some tables have data stored in two encodings
    if (strlen($str) && !strlen($ret)) {
      error_log('ISO charset date = ' . date('d.m.Y H:i:s') . ',STR = ' . $str);
      $str = iconv('ISO-8859-1', 'UTF-8', $str);
    }

    if (!empty($str) && is_object($str)) {
      error_log('Exception convertPhpObjToDom date = ' . date('d.m.Y H:i:s') . ', object class = ' . get_class($str));
      if (method_exists($str , '__toString')) {
        $str = $str->__toString();
      } else {
        return $str;
      }
    }

    if (!empty($str) && is_string($str) && !self::checkCreateXml($str)) {
      error_log('Exception convertPhpObjToDom date = ' . date('d.m.Y H:i:s') . ', STR = ' . $str);
      $str = htmlentities($str, null, OP_API::$encoding);
    }
    return $str;
  }
  static function decode ($str)
  {
    return $str;
  }
  static function createRequest ($xmlStr = null)
  {
    return new OP_Request ($xmlStr);
  }
  static function createReply ($xmlStr = null)
  {
    return new OP_Reply ($xmlStr);
  }
  protected function _send ($str)
  {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $this->url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_HEADER, 0);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $str);
    curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
    $ret = curl_exec ($ch);
    $errno = curl_errno($ch);
    $this->error = $error = curl_error($ch);
    curl_close ($ch);

    if ($errno) {
      error_log("CURL error. Code: $errno, Message: $error");
      return false;
    } else {
      return $ret;
    }
  }
  // convert SimpleXML to PhpObj
  public static function convertXmlToPhpObj ($node)
  {
    $ret = array();

    if (is_object($node) && $node->hasChildNodes()) {
      foreach ($node->childNodes as $child) {
        $name = self::decode($child->nodeName);
        if ($child->nodeType == XML_TEXT_NODE) {
          $ret = self::decode($child->nodeValue);
        } else {
          if ('array' === $name) {
            return self::parseArray($child);
          } else {
            $ret[$name] = self::convertXmlToPhpObj($child);
          }
        }
      }
    }
    if(is_string($ret)){
      return (0 < strlen($ret)) ? $ret : null;
    }
    else if(is_array($ret)){
      return (!empty($ret)) ? $ret : null;
    }
    else if(is_null($ret)){
      return null;
    }
    else{
      return false;
    }
  }
  // parse array
  protected static function parseArray ($node)
  {
    $ret = array();
    foreach ($node->childNodes as $child) {
      $name = self::decode($child->nodeName);
      if ('item' !== $name) {
        throw new OP_API_Exception('Wrong message format', 4006);
      }
      $ret[] = self::convertXmlToPhpObj($child);
    }
    return $ret;
  }
  /**
  * converts php-structure to DOM-object.
  *
  * @param array $arr php-structure
  * @param SimpleXMLElement $node parent node where new element to attach
  * @param DOMDocument $dom DOMDocument object
  * @return SimpleXMLElement
  */
  public static function convertPhpObjToDom ($arr, $node, $dom)
  {
    if (is_array($arr)) {
      /**
      * If arr has integer keys, this php-array must be converted in
      * xml-array representation (<array><item>..</item>..</array>)
      */
      $arrayParam = array();
      foreach ($arr as $k => $v) {
        if (is_integer($k)) {
          $arrayParam[] = $v;
        }
      }
      if (0 < count($arrayParam)) {
        $node->appendChild($arrayDom = $dom->createElement("array"));
        foreach ($arrayParam as $key => $val) {
          $new = $arrayDom->appendChild($dom->createElement('item'));
          self::convertPhpObjToDom($val, $new, $dom);
        }
      } else {
        foreach ($arr as $key => $val) {
          $new = $node->appendChild(
          $dom->createElement(self::encode($key))
          );
          self::convertPhpObjToDom($val, $new, $dom);
        }
      }
    } elseif (!is_object($arr)) {
      $node->appendChild($dom->createTextNode(self::encode($arr)));
    }
  }

  public function request($method, $request_array = [], $error_notice = true) {

    $username = get_option('wcdnr_openprovider_username');
    $hash = get_option('wcdnr_openprovider_hash');

    $request = new OP_Request;
    $request->setCommand($method)
    ->setAuth(array('username' => $username, 'hash' => $hash))
    ->setArgs($request_array);

    $reply = $this->process($request);

    if ($error_notice && $reply->getFaultCode() != 0) {
      // wc_add_notice('<p>' . $reply->getFaultString(), 'error' );
      error_log($reply->getFaultString() );
    }

    return $reply;
  }

  public function get_quote($domain, $operation='create', $period = 1) {
    if(is_array($domain)) {
      $domainName = join('.', $domain);
      $extension = $domain['extension'];
      $name = $domain['name'];
    } else {
      $domainName = $domain;
      $extension = preg_replace('/^.*\./', '', $domain);
      $name = preg_replace("/\.$extension$/", '', $domain);
    }
    $transient_key = "wcdnr_openprovider_${operation}_price_${period}_${domainName}";
    $price = get_transient($transient_key);
    if(!$price) {
      $reply = $this->request('retrievePriceDomainRequest', array(
        'domain' => array(
          'name' => $name,
          'extension' => $extension,
        ),
        'period' => $period,
        'operation' => $operation,
      ));

      if ($reply->getFaultCode() == 0) {
        $result = $reply->getValue();
        $price = $result['price']['reseller']['price'];
        if($result['isPromotion']) {
          $price = $result['membershipPrice']['reseller']['price'];
        }
      }
      set_transient($transient_key, $price, 86400); // 5 for debug, 86400 in prod
    }

    return $price;
  }

  public function getcontact($handle = NULL) {
    if(empty($handle)) return false;
    $transient_key = 'wcdnr_openprovider_contact_' . $handle;
    $contact = get_transient($transient_key);
    if(!$contact) {
      error_log("no cache for $handle, fetching");
      $reply = $this->request('retrieveCustomerRequest', array(
        'handle' => $handle,
      ));
      if ($reply->getFaultCode() == 0) {
        $contact = $reply->getValue();
      } else {
        $contact = false;
      }
      set_transient($transient_key, $contact, 86400); // 5 for debug, 86400 in prod
    }

    return $contact;
  }

  public function fetchDomains($force = false) {
    // cron disabled  for debug only, in prod we'll run this task in cron only
    if ( defined( 'DOING_CRON' ) ) return;
    // Let's make sure all required functions and classes exist
    if( ! function_exists( 'wc_create_order' ) || ! function_exists( 'wcs_create_subscription' ) || ! class_exists( 'WC_Subscriptions_Product' ) ){
      // we can't do anything without woocommerce but maybe we don't need subscription
      return false;
    }
    if ( wp_doing_ajax() ) return; // this one we'll probably keep
    if ( get_option('wcdnr_openprovider_migrate') != 'yes' ) return;

    $domain_product_id = get_option('wcdnr_domain_registration_product', NULL);
    if(! $domain_product_id) {
      error_log('domain name product not set, cannot import domains from registry');
      return;
    }

    $debug = array();

    if ( wp_cache_get('wcdnr_fetch_domains', 'wcdnr') ) return;
    if ( get_transient('wcdnr_fetch_domains') ) {
      $records = get_transient('wcdnr_fetch_domains');
    } else {
      $batchlimit = 1000; // registrar max limit is 1000 anyway
      $offset = 0;

      $records = array();
      while (true) {
        $reply = $this->request('searchDomainRequest', array(
          // 'domainNamePattern' => '',
          // 'withAdditionalData' => true,
          'orderBy' => 'orderDate',
          'limit' => 1,
          'offset' => $offset,
        ));

        if ($reply->getFaultCode() == 0) {
          $batch = $reply->getValue()['results'];
          if(!empty($batch)) {
            $records = array_merge($records, $batch);
          } else break;
        } else break;
        $offset = $offset + $batchlimit;
      }
      set_transient('wcdnr_fetch_domains', $records, 86400); // 5 for debug, 86400 in prod
    }

    foreach ($records as $record) {
      $contact = OP_API::getcontact($record['ownerHandle']);
      if(!$contact) continue;

      $order_args = array();
      $user = get_user_by('email', $contact['email']);
      if($user) $domain['customer_id'] = $user->id;

      $domain['domainName'] = $record['domain']['name'] . '.' . $record['domain']['extension'];
      $domain['price'] = wcdnr_selling_price(OP_API::get_quote($record['domain'], 'renew'));

      $domain['address'] = array_filter(array(
          'first_name' => $contact['name']['firstName'],
          'last_name'  => $contact['name']['lastName'],
          'company'    => $contact['companyName'],
          'email'      => $contact['email'],
          'phone'      => join('-', $contact['phone']),
          'address_1'  => trim($contact['address']['number'] . ' ' . $contact['address']['street']),
          'address_2'  => NULL,
          'city'       => $contact['address']['city'],
          'state'      => NULL,
          'postcode'   => $contact['address']['zipcode'],
          'country'    => $contact['address']['country'],
      ));
      break; // for debug only
    }

    error_log(sprintf(
      '%s result (%s): %s',
      __FUNCTION__,
      count($records), ''
      // . "\n" . print_r($records[0], true)
      . "\nOwner: " . print_r($contact, true)
      . "\nDomain: " . print_r($domain, true)
      . "\n" . join("\n", $debug),
    ));

    wp_cache_set('wcdnr_fetch_domains', true, 'wcdnr');
  }
}

class OP_Request
{
  protected $cmd = null;
  protected $args = null;
  protected $username = null;
  protected $password = null;
  protected $hash = null;
  protected $token = null;
  protected $ip = null;
  protected $language = null;
  protected $raw = null;
  protected $dom = null;
  protected $misc = null;
  protected $filters = [];
  public function __construct ($str = null)
  {
    if ($str) {
      $this->setContent($str);
    }
  }
  public function addFilter($filter)
  {
    $this->filters[] = $filter;
  }
  public function setContent($str)
  {
    $this->raw = $str;
  }
  protected function initDom()
  {
    if ($this->raw) {
      $this->dom = new DOMDocument;
      $this->dom->loadXML($this->raw, LIBXML_NOBLANKS);
    }
  }
  public function getDom()
  {
    if (!$this->dom) {
      $this->initDom();
    }
    return $this->dom;
  }
  protected function setDom($dom)
  {
    $this->dom = $dom;
  }
  public function parseContent()
  {
    $this->initDom();
    if (!$this->dom) {
      return;
    }
    foreach ($this->filters as $f) {
      $f->filter($this);
    }
    $this->_retrieveDataFromDom($this->dom);
  }
  /*
  * Parse request string to assign object properties with command name and
  * arguments structure
  *
  * @return void
  *
  * @uses OP_Request::__construct()
  */
  protected function _retrieveDataFromDom ($dom)
  {
    $arr = OP_API::convertXmlToPhpObj($dom->documentElement);
    list($dummy, $credentials) = each($arr);
    list($this->cmd, $this->args) = each($arr);
    $this->username = $credentials['username'];
    $this->password = $credentials['password'];
    if (isset($credentials['hash'])) {
      $this->hash = $credentials['hash'];
    }
    if (isset($credentials['misc'])) {
      $this->misc = $credentials['misc'];
    }
    $this->token = isset($credentials['token']) ? $credentials['token'] : null;
    $this->ip = isset($credentials['ip']) ? $credentials['ip'] : null;
    if (isset($credentials['language'])) {
      $this->language = $credentials['language'];
    }
  }
  public function setCommand ($v)
  {
    $this->cmd = $v;
    return $this;
  }
  public function getCommand ()
  {
    return $this->cmd;
  }
  public function setLanguage ($v)
  {
    $this->language = $v;
    return $this;
  }
  public function getLanguage ()
  {
    return $this->language;
  }
  public function setArgs ($v)
  {
    $this->args = $v;
    return $this;
  }
  public function getArgs ()
  {
    return $this->args;
  }
  public function setMisc ($v)
  {
    $this->misc = $v;
    return $this;
  }
  public function getMisc ()
  {
    return $this->misc;
  }
  public function setAuth ($args)
  {
    $this->username = isset($args["username"]) ? $args["username"] : null;
    $this->password = isset($args["password"]) ? $args["password"] : null;
    $this->hash = isset($args["hash"]) ? $args["hash"] : null;
    $this->token = isset($args["token"]) ? $args["token"] : null;
    $this->ip = isset($args["ip"]) ? $args["ip"] : null;
    $this->misc = isset($args["misc"]) ? $args["misc"] : null;
    return $this;
  }
  public function getAuth ()
  {
    return array(
    "username" => $this->username,
    "password" => $this->password,
    "hash" => $this->hash,
    "token" => $this->token,
    "ip" => $this->ip,
    "misc" => $this->misc,
    );
  }
  public function getRaw ()
  {
    if (!$this->raw) {
      $this->raw .= $this->_getRequest();
    }
    return $this->raw;
  }
  public function _getRequest ()
  {
    $dom = new DOMDocument('1.0', OP_API::$encoding);

    $credentialsElement = $dom->createElement('credentials');
    $usernameElement = $dom->createElement('username');
    $usernameElement->appendChild(
    $dom->createTextNode(OP_API::encode($this->username))
    );
    $credentialsElement->appendChild($usernameElement);

    $passwordElement = $dom->createElement('password');
    $passwordElement->appendChild(
    $dom->createTextNode(OP_API::encode($this->password))
    );
    $credentialsElement->appendChild($passwordElement);

    $hashElement = $dom->createElement('hash');
    $hashElement->appendChild(
    $dom->createTextNode(OP_API::encode($this->hash))
    );
    $credentialsElement->appendChild($hashElement);

    if (isset($this->language)) {
      $languageElement = $dom->createElement('language');
      $languageElement->appendChild($dom->createTextNode($this->language));
      $credentialsElement->appendChild($languageElement);
    }

    if (isset($this->token)) {
      $tokenElement = $dom->createElement('token');
      $tokenElement->appendChild($dom->createTextNode($this->token));
      $credentialsElement->appendChild($tokenElement);
    }

    if (isset($this->ip)) {
      $ipElement = $dom->createElement('ip');
      $ipElement->appendChild($dom->createTextNode($this->ip));
      $credentialsElement->appendChild($ipElement);
    }

    if (isset($this->misc)) {
      $miscElement = $dom->createElement('misc');
      $credentialsElement->appendChild($miscElement);
      OP_API::convertPhpObjToDom($this->misc, $miscElement, $dom);
    }

    $rootElement = $dom->createElement('openXML');
    $rootElement->appendChild($credentialsElement);

    $rootNode = $dom->appendChild($rootElement);
    $cmdNode = $rootNode->appendChild(
    $dom->createElement($this->getCommand())
    );
    OP_API::convertPhpObjToDom($this->args, $cmdNode, $dom);

    return $dom->saveXML();
  }
}
class OP_Reply
{
  protected $faultCode = 0;
  protected $faultString = null;
  protected $value = array();
  protected $warnings = array();
  protected $raw = null;
  protected $dom = null;
  protected $filters = [];
  protected $maintenance = null;

  public function __construct ($str = null) {
    if ($str) {
      $this->raw = $str;
      $this->_parseReply($str);
    }
  }
  protected function _parseReply ($str = '')
  {
    $dom = new DOMDocument;
    $result = $dom->loadXML(trim($str));
    if (!$result) {
      error_log("Cannot parse xml: '$str'");
    }

    $arr = OP_API::convertXmlToPhpObj($dom->documentElement);
    if ((!is_array($arr) && trim($arr) == '') ||
    $arr['reply']['code'] == 4005)
    {
      throw new OP_API_Exception(__("API is temporarily unavailable due to maintenance", 'wcdnr'), 4005);
    }

    $this->faultCode = (int) $arr['reply']['code'];
    $this->faultString = $arr['reply']['desc'];
    $this->value = $arr['reply']['data'];
    if (isset($arr['reply']['warnings'])) {
      $this->warnings = $arr['reply']['warnings'];
    }
    if (isset($arr['reply']['maintenance'])) {
      $this->maintenance = $arr['reply']['maintenance'];
    }
  }
  public function encode ($str)
  {
    return OP_API::encode($str);
  }
  public function setFaultCode ($v)
  {
    $this->faultCode = $v;
    return $this;
  }
  public function setFaultString ($v)
  {
    $this->faultString = $v;
    return $this;
  }
  public function setValue ($v)
  {
    $this->value = $v;
    return $this;
  }
  public function getValue ()
  {
    return $this->value;
  }
  public function setWarnings ($v)
  {
    $this->warnings = $v;
    return $this;
  }
  public function getDom ()
  {
    return $this->dom;
  }
  public function getWarnings ()
  {
    return $this->warnings;
  }
  public function getMaintenance ()
  {
    return $this->maintenance;
  }
  public function getFaultString () {
    return $this->faultString;
  }
  public function getFaultCode ()
  {
    return $this->faultCode;
  }
  public function getRaw ()
  {
    if (!$this->raw) {
      $this->raw .= $this->_getReply ();
    }
    return $this->raw;
  }
  public function addFilter($filter)
  {
    $this->filters[] = $filter;
  }
  public function _getReply ()
  {
    $dom = new DOMDocument('1.0', OP_API::$encoding);
    $rootNode = $dom->appendChild($dom->createElement('openXML'));
    $replyNode = $rootNode->appendChild($dom->createElement('reply'));
    $codeNode = $replyNode->appendChild($dom->createElement('code'));
    $codeNode->appendChild($dom->createTextNode($this->faultCode));
    $descNode = $replyNode->appendChild($dom->createElement('desc'));
    $descNode->appendChild(
    $dom->createTextNode(OP_API::encode($this->faultString))
    );
    $dataNode = $replyNode->appendChild($dom->createElement('data'));
    OP_API::convertPhpObjToDom($this->value, $dataNode, $dom);
    if (0 < count($this->warnings)) {
      $warningsNode = $replyNode->appendChild($dom->createElement('warnings'));
      OP_API::convertPhpObjToDom($this->warnings, $warningsNode, $dom);
    }
    $this->dom = $dom;
    foreach ($this->filters as $f) {
      $f->filter($this);
    }
    return $dom->saveXML();
  }

  /**
   * Factice function to add the registrar response strings to localization.
   */
  function localization_strings() {
      $strings=array(
        __('Your domain request contains an invalid extension!', 'wcdnr'),
        __('Name reserved by IANA', 'wcdnr'),
        __('Domain exists', 'wcdnr'),
      );
  }

}

$Openprovider = new OP_API ('https://api.openprovider.eu');
// $Openprovider->fetchDomains();
