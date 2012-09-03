<?

namespace okapi;

/**
 * Use this class to access OC session variables. This is especially useful if
 * you want to determine which user is currently logged in to OC.
 */
class OCSession
{
	/** Return ID of currently logged in user or NULL if no user is logged in. */
	public static function get_user_id()
	{
		static $cached_result = false;
		if ($cached_result !== false)
			return $cached_result;
		
		$OC_data = unserialize(base64_decode(Settings::get('OC_COOKIE_NAME')));
		$OC_sessionid = $OC_data['sessionid'];
		unset($OC_data);
		
		if (!$OC_sessionid)
			return null;
		
		return Db::select_value("select user_id from sys_sessions where uuid='".mysql_real_escape_string($OC_sessionid)."'");
	}
}