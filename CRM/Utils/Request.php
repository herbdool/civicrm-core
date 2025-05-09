<?php
/*
 +--------------------------------------------------------------------+
 | Copyright CiviCRM LLC. All rights reserved.                        |
 |                                                                    |
 | This work is published under the GNU AGPLv3 license with some      |
 | permitted exceptions and without any warranty. For full license    |
 | and copyright information, see https://civicrm.org/licensing       |
 +--------------------------------------------------------------------+
 */

/**
 *
 * @package CRM
 * @copyright CiviCRM LLC https://civicrm.org/licensing
 */

/**
 * Class for managing a http request
 */
class CRM_Utils_Request {

  /**
   * Get a unique ID for the request.
   *
   * This unique ID is assigned to mysql when the connection is opened and is
   * available in PHP.
   *
   * The intent is that it is available for logging purposes and for triggers.
   *
   * The resulting string is 17 characters long. This consists of 13 characters of uniqid
   * and 4 more random characters.
   *
   * Uniqid is unique to the microsecond - to make it more unique we add 4 more characters
   * but stop short of the full 23 character string that a prefix would generate.
   *
   * It is intended that this string will be saved to log tables so striking a balance between
   * uniqueness and length is important. Note that I did check & lining up with byte values
   * (e.g 16 characters) does not confer any benefits. Using a CHAR field rather than VARCHAR
   * may improve speed, if indexed.
   *
   * @return string
   */
  public static function id() {
    if (!isset(\Civi::$statics[__CLASS__]['id'])) {
      \Civi::$statics[__CLASS__]['id'] = uniqid() . CRM_Utils_String::createRandom(4, CRM_Utils_String::ALPHANUMERIC);
    }
    return \Civi::$statics[__CLASS__]['id'];
  }

  /**
   * Retrieve a value from the request (GET/POST/REQUEST)
   *
   * @param string $name
   *   Name of the variable to be retrieved.
   * @param string $type
   *   Type of the variable (see CRM_Utils_Type for details).
   * @param object $store
   *   Session scope where variable is stored.
   * @param bool $abort
   *   TRUE, if the variable is required.
   * @param mixed $default
   *   Default value of the variable if not present.
   * @param string $method
   *   Where to look for the variable - 'GET', 'POST' or 'REQUEST'.
   *
   * @return mixed
   *   The value of the variable
   *
   * @throws \CRM_Core_Exception
   */
  public static function retrieve($name, $type, $store = NULL, $abort = FALSE, $default = NULL, $method = 'REQUEST') {

    $value = NULL;
    switch ($method) {
      case 'GET':
        $value = self::getValue($name, $_GET);
        break;

      case 'POST':
        $value = self::getValue($name, $_POST);
        break;

      default:
        $value = self::getValue($name, $_REQUEST);
        break;
    }

    if (isset($value)) {
      $value = CRM_Utils_Type::validate($value, $type, $abort, $name);
    }

    if (!isset($value) && $store) {
      $value = $store->get($name);
    }

    if (!isset($value) && $abort) {
      throw new CRM_Core_Exception(ts('Could not find valid value for %1', [1 => $name]));
    }

    if (!isset($value) && $default) {
      $value = $default;
    }

    // minor hack for action
    if ($name === 'action') {
      if (!is_numeric($value) && is_string($value)) {
        $value = CRM_Core_Action::resolve($value);
      }
    }

    if (isset($value) && $store) {
      $store->set($name, $value);
    }

    return $value;
  }

  /**
   * @param string $name
   *   Name of the variable to be retrieved.
   *
   * @param array $method - '$_GET', '$_POST' or '$_REQUEST'.
   *
   * @return mixed
   *   The value of the variable
   */
  protected static function getValue($name, $method) {
    if (isset($method[$name])) {
      return $method[$name];
    }
    // CRM-18384 - decode incorrect keys generated when &amp; is present in url
    foreach (($method ?? []) as $key => $value) {
      if (str_contains($key, 'amp;')) {
        $method[str_replace('amp;', '', $key)] = $method[$key];
        if (isset($method[$name])) {
          return $method[$name];
        }
        else {
          continue;
        }
      }
    }
    return NULL;
  }

  /**
   * @deprecated
   *
   * We should use a function that checks url values.
   *
   * This is a replacement for $_REQUEST which includes $_GET/$_POST
   * but excludes $_COOKIE / $_ENV / $_SERVER.
   *
   * @return array
   */
  public static function exportValues() {
    // For more discussion of default $_REQUEST handling, see:
    // http://www.php.net/manual/en/reserved.variables.request.php
    // http://www.php.net/manual/en/ini.core.php#ini.request-order
    // http://www.php.net/manual/en/ini.core.php#ini.variables-order

    $result = [];
    if ($_GET) {
      $result = array_merge($result, $_GET);
    }
    if ($_POST) {
      $result = array_merge($result, $_POST);
    }
    return $result;
  }

  /**
   * Retrieve a variable from the http request.
   *
   * @param string $name
   *   Name of the variable to be retrieved.
   * @param string $type
   *   Type of the variable (see CRM_Utils_Type for details).
   *   Most common options are:
   *   - 'Integer'
   *   - 'Positive'
   *   - 'CommaSeparatedIntegers'
   *   - 'Boolean'
   *   - 'String'
   *
   * @param mixed $defaultValue
   *   Default value of the variable if not present.
   * @param bool $isRequired
   *   Is the variable required for this function to proceed without an exception.
   * @param string $method
   *   Where to look for the value - GET|POST|REQUEST
   *
   * @return mixed
   * @throws \CRM_Core_Exception
   */
  public static function retrieveValue($name, $type, $defaultValue = NULL, $isRequired = FALSE, $method = 'REQUEST') {
    $null = NULL;
    return CRM_Utils_Request::retrieve((string) $name, (string) $type, $null, (bool) $isRequired, $defaultValue, $method, TRUE);
  }

  /**
   * Retrieve the component from the action attribute of a form.
   *
   * Contribution Page forms and Event Management forms detect the value of a
   * component (and therefore the desired tab key) by reaching into the "action"
   * attribute of a form and reading the final item of the path. In WordPress,
   * however, the URL may be urlencoded, and so the URL may need to be decoded
   * before parsing it.
   *
   * @see https://lab.civicrm.org/dev/wordpress/issues/12#note_10699
   *
   * @param array $attributes
   *   The form attributes array.
   *
   * @return string
   *   The desired value.
   */
  public static function retrieveComponent($attributes) {
    $url = $attributes['action'] ?? NULL;
    // Whilst the following is a fallible universal test for urlencoded URLs,
    // thankfully the "action" URL has a limited and predictable form and
    // therefore this comparison is sufficient for our purposes.
    if (rawurlencode(rawurldecode($url)) !== $url) {
      $value = strtolower(basename(rawurldecode($url)));
    }
    else {
      $value = strtolower(basename($url));
    }
    return $value;
  }

}
