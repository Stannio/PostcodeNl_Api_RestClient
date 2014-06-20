<?php
/**
	PostcodeNl

	LICENSE:
	This source file is subject to the Simplified BSD license that is
	bundled	with this package in the file LICENSE.txt.
	It is also available through the world-wide-web at this URL:
	https://api.postcode.nl/license/simplified-bsd
	If you did not receive a copy of the license and are unable to
	obtain it through the world-wide-web, please send an email
	to info@postcode.nl so we can send you a copy immediately.

	Copyright (c) 2013 Postcode.nl B.V. (http://www.postcode.nl)
*/

/** Common superclass for Exceptions raised by this class. */
class PostcodeNl_Api_RestClient_Exception extends Exception {}
/** Exception raised when user input is invalid. */
class PostcodeNl_Api_RestClient_InputInvalidException extends PostcodeNl_Api_RestClient_Exception {}
/** Exception raised when user input is valid, but no address could be found. */
class PostcodeNl_Api_RestClient_AddressNotFoundException extends PostcodeNl_Api_RestClient_Exception {}
/** Exception raised when an unexpected error occurred in this client. */
class PostcodeNl_Api_RestClient_ClientException extends PostcodeNl_Api_RestClient_Exception {}
/** Exception raised when an unexpected error occurred on the remote service. */
class PostcodeNl_Api_RestClient_ServiceException extends PostcodeNl_Api_RestClient_Exception {}
/** Exception raised when there is a authentication problem.
	In a production environment, you probably always want to catch, log and hide these exceptions.
*/
class PostcodeNl_Api_RestClient_AuthenticationException extends PostcodeNl_Api_RestClient_Exception {}

/**
	Class to connect to the Postcode.nl API web service.

	References:
		<https://api.postcode.nl/>
*/
class PostcodeNl_Api_RestClient
{
	/** (string) Version of the client */
	const VERSION = '1.0.1.0';
	/** (int) Maximum number of seconds allowed to set up the connection. */
	const CONNECTTIMEOUT = 3;
	/** (int) Maximum number of seconds allowed to receive the response. */
	const TIMEOUT = 10;
	/** (string) URL where the REST web service is located */
	protected $_restApiUrl = 'https://api.postcode.nl/rest';
	/** (string) Internal storage of the application key of the authentication. */
	protected $_appKey = '';
	/** (string) Internal storage of the application secret of the authentication. */
	protected $_appSecret = '';
	/** (boolean) If debug data is stored. */
	protected $_debugEnabled = false;
	/** (mixed) Debug data storage. */
	protected $_debugData = null;

	/**
		Construct the client.

		Parameters:
			appKey - (string) Application Key as generated by Postcode.nl
			appSecret - (string) Application Secret as generated by Postcode.nl
	*/
	public function __construct($appKey, $appSecret)
	{
		$this->_appKey = $appKey;
		$this->_appSecret = $appSecret;

		if (empty($this->_appKey) || empty($this->_appSecret))
			throw new PostcodeNl_Api_RestClient_ClientException('No application key / secret configured, you can obtain these at https://api.postcode.nl.');

		if (!extension_loaded('curl'))
			throw new PostcodeNl_Api_RestClient_ClientException('Cannot use Postcode.nl API client, the server needs to have the PHP `cURL` extension installed.');

		$version = curl_version();
		$sslSupported = ($version['features'] & CURL_VERSION_SSL);
		if (!$sslSupported)
			throw new PostcodeNl_Api_RestClient_ClientException('Cannot use Postcode.nl API client, the server cannot connect to HTTPS urls. (`cURL` extension needs support for SSL)');
	}

	/**
		Toggle debug option.

		Parameters:
			debugEnabled - (boolean) what to set the option to
	*/
	public function setDebugEnabled($debugEnabled = true)
	{
		$this->_debugEnabled = (boolean)$debugEnabled;
		if (!$this->_debugEnabled)
			$this->_debugData = null;
	}

	/**
		Get the debug data gathered so far.

		Returns:
			(mixed) Debug data
	*/
	public function getDebugData()
	{
		return $this->_debugData;
	}

	/**
		Look up an address by postcode and house number.

		Parameters:
			postcode - (string) Dutch postcode in the '1234AB' format
			houseNumber - (string) House number (may contain house number addition, will be separated automatically)
			houseNumberAddition - (string) House number addition
			validateHouseNumberAddition - (boolean) Strictly validate the addition

		Returns:
			(array) The address found
				street - (string) Official name of the street.
				houseNumber - (int) House number
				houseNumberAddition - (string|null) House number addition if given and validated, null if addition is not valid / not found
				postcode - (string) Postcode
				city - (string) Official city name
				municipality - (string) Official municipality name
				province - (string) Official province name
				rdX - (int) X coordinate of the Dutch Rijksdriehoeksmeting
				rdY - (int) Y coordinate of the Dutch Rijksdriehoeksmeting
				latitude - (float) Latitude of the address (front door of the premise)
				longitude - (float) Longitude of the address
				bagNumberDesignationId - (string) Official Dutch BAG id
				bagAddressableObjectId - (string) Official Dutch BAG Address Object id
				addressType - (string) Type of address, see reference link
				purposes - (array) Array of strings, each indicating an official Dutch 'usage' category, see reference link
				surfaceArea - (int) Surface area of object in square meters (all floors)
				houseNumberAdditions - (array) All housenumber additions which are known for the housenumber given.

		Reference:
			<https://api.postcode.nl/Documentation/api>
	*/
	public function lookupAddress($postcode, $houseNumber, $houseNumberAddition = '', $validateHouseNumberAddition = false)
	{
		// Remove spaces in postcode ('1234 AB' should be '1234AB')
		$postcode = str_replace(' ', '', trim($postcode));
		$houseNumber = trim($houseNumber);
		$houseNumberAddition = trim($houseNumberAddition);

		if ($houseNumberAddition == '')
		{
			// If people put the housenumber addition in the housenumber field - split this.
			list($houseNumber, $houseNumberAddition) = $this->splitHouseNumber($houseNumber);
		}

		// Test postcode format
		if (!$this->isValidPostcodeFormat($postcode))
			throw new PostcodeNl_Api_RestClient_InputInvalidException('Postcode `'. $postcode .'` needs to be in the 1234AB format.');
		// Test housenumber format
		if (!ctype_digit($houseNumber))
			throw new PostcodeNl_Api_RestClient_InputInvalidException('House number `'. $houseNumber .'` must contain digits only.');

		// Create the REST url we want to retrieve. (making sure we escape any user input)
		$url = $this->_restApiUrl .'/addresses/' . urlencode($postcode). '/'. urlencode($houseNumber) . '/'. urlencode($houseNumberAddition);

		// Connect using cURL
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		// We want the response returned to us.
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		// Maximum number of seconds allowed to set up the connection.
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::CONNECTTIMEOUT);
		// Maximum number of seconds allowed to receive the response.
		curl_setopt($ch, CURLOPT_TIMEOUT, self::TIMEOUT);
		// How do we authenticate ourselves? Using HTTP BASIC authentication (http://en.wikipedia.org/wiki/Basic_access_authentication)
		curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
		// Set our key as 'username' and our secret as 'password'
		curl_setopt($ch, CURLOPT_USERPWD, $this->_appKey .':'. $this->_appSecret);
		// To be tidy, we identify ourselves with a User Agent. (not required)
		curl_setopt($ch, CURLOPT_USERAGENT, 'PostcodeNl_Api_RestClient/' . self::VERSION .' PHP/'. phpversion());

		// Various debug options
		if ($this->_debugEnabled)
		{
			curl_setopt($ch, CURLINFO_HEADER_OUT, true);
			curl_setopt($ch, CURLOPT_HEADER, true);
		}

		// Do the request
		$response = curl_exec($ch);
		// Remember the HTTP status code we receive
		$responseStatusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$responseStatusCodeClass = floor($responseStatusCode/100)*100;
		// Any errors? Remember them now.
		$curlError = curl_error($ch);
		$curlErrorNr = curl_errno($ch);

		if ($this->_debugEnabled)
		{
			$this->_debugData['request'] = curl_getinfo($ch, CURLINFO_HEADER_OUT);
			$this->_debugData['response'] = $response;

			// Strip off header that was added for debug purposes.
			$response = substr($response, strpos($response, "\r\n\r\n") + 4);
		}

		// And close the cURL handle
		curl_close($ch);

		if ($curlError)
		{
			// We could not connect, cURL has the reason. (we hope)
			throw new PostcodeNl_Api_RestClient_ClientException('Connection error `'. $curlErrorNr .'`: `'. $curlError .'`', $curlErrorNr);
		}

		// Parse the response as JSON
		$jsonResponse = json_decode($response, true);

		if ($responseStatusCodeClass != 200)
		{
			if (!is_array($jsonResponse) || !isset($jsonResponse['exceptionId']))
				throw new PostcodeNl_Api_RestClient_ClientException('Postcode.nl API returned status `'. $responseStatusCode .'`.');

			// Received an error message from api.postcode.nl
			switch ($jsonResponse['exceptionId'])
			{
				case 'PostcodeNl_Controller_Plugin_HttpBasicAuthentication_Exception':
				case 'PostcodeNl_Controller_Plugin_HttpBasicAuthentication_NotAuthorizedException':
					// Could not authenticate, probably invalid or no key/secret configured
					throw new PostcodeNl_Api_RestClient_AuthenticationException($jsonResponse['exception']);
				case 'PostcodeNl_Controller_Plugin_HttpBasicAuthentication_PasswordNotCorrectException':
					throw new PostcodeNl_Api_RestClient_AuthenticationException('Secret not correct.');
				case 'React_Controller_Action_InvalidParameterException':
				case 'PostcodeNl_Controller_Address_InvalidPostcodeException':
				case 'PostcodeNl_Controller_Address_InvalidHouseNumberException':
				case 'PostcodeNl_Controller_Address_NoPostcodeSpecifiedException':
				case 'PostcodeNl_Controller_Address_NoHouseNumberSpecifiedException':
				case 'React_Model_Property_Validation_Number_ValueTooHighException':
					// Postcode received cannot be a valid postcode (must be like '9999AZ' or '9999 AZ')
					throw new PostcodeNl_Api_RestClient_InputInvalidException($jsonResponse['exception']);
				case 'PostcodeNl_Service_PostcodeAddress_AddressNotFoundException':
					// Could not find an address for the input values given
					throw new PostcodeNl_Api_RestClient_AddressNotFoundException($jsonResponse['exception']);
				default:
					// Other error
					throw new PostcodeNl_Api_RestClient_ServiceException($jsonResponse['exception']);
			}
		}

		if (!is_array($jsonResponse) || !isset($jsonResponse['postcode']))
		{
			// We received a response, but we did not understand it...
			throw new PostcodeNl_Api_RestClient_ClientException('Did not understand Postcode.nl API response: `'. $response .'`.');
		}

		// Strictly enforce housenumber addition validity
		if ($validateHouseNumberAddition)
		{
			if ($jsonResponse['houseNumberAddition'] === null)
				throw new PostcodeNl_Api_RestClient_InputInvalidException('Housenumber addition `'. $houseNumberAddition .'` is not known for this address, valid additions are: `'. implode('`, `', $jsonResponse['houseNumberAdditions']) .'`.');
		}

		// Successful response!
		return $jsonResponse;
	}

	/**
		Validate a postcode string for correct format.
		(is 1234AB, or 1234ab - no space in between!)

	 	Parameters:
	 		postcode - (string) Postcode input

		Returns
	 		(boolean) if the postcode format is correct
	*/
	public function isValidPostcodeFormat($postcode)
	{
		return (boolean)preg_match('~^[0-9]{4}[a-zA-Z]{2}$~', $postcode);
	}

	/**
		Split a housenumber addition from a housenumber.
		Examples: "123 2", "123 rood", "123a", "123a4", "123-a", "123 II"
		(the official notation is to separate the housenumber and addition with a single space)

		Parameters:
			houseNumber - (string) Housenumber input

		Returns
			(array) split 'houseNumber' and 'houseNumberAddition'
	*/
	public function splitHouseNumber($houseNumber)
	{
		$houseNumberAddition = '';
		if (preg_match('~^(?<number>[0-9]+)(?:[^0-9a-zA-Z]+(?<addition1>[0-9a-zA-Z ]+)|(?<addition2>[a-zA-Z](?:[0-9a-zA-Z ]*)))?$~', $houseNumber, $match))
		{
			$houseNumber = $match['number'];
			$houseNumberAddition = isset($match['addition2']) ? $match['addition2'] : (isset($match['addition1']) ? $match['addition1'] : '');
		}

		return array($houseNumber, $houseNumberAddition);
	}
}
