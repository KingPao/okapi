<?php

namespace okapi;

use OAuthDataStore;

# WRTODO: dodać usuwanie starych request tokenów i nonces

require_once($rootpath.'lib/common.inc.php');

class OkapiDataStore extends OAuthDataStore
{
	public function lookup_consumer($consumer_key)
	{
		$rs = sql("
			select `key`, secret, name, url, email
			from okapi_consumers
			where `key` = '".mysql_real_escape_string($consumer_key)."'
		");
		$row = sql_fetch_assoc($rs);
		mysql_free_result($rs);
		if (!$row)
			return null;
		return new OkapiConsumer($row['key'], $row['secret'], $row['name'],
			$row['url'], $row['email']);
	}

	public function lookup_token(OkapiConsumer $consumer, $token_type, $token)
	{
		$rs = sql("
			select `key`, consumer_key, secret, token_type, user_id, verifier, callback
			from okapi_tokens
			where
				consumer_key = '".mysql_real_escape_string($consumer->key)."'
				and token_type = '".mysql_real_escape_string($token_type)."'
				and `key` = '".mysql_real_escape_string($token)."'
		");
		$row = sql_fetch_assoc($rs);
		mysql_free_result($rs);
		if (!$row)
			return null;
		switch ($row['token_type'])
		{
			case 'request':
				return new OkapiRequestToken($row['key'], $row['secret'],
					$row['consumer_key'], $row['callback'], $row['user_id'],
					$row['verifier']);
			case 'access':
				return new OkapiAccessToken($row['key'], $row['secret'],
					$row['consumer_key'], $row['user_id']);
			default:
				throw new Exception();
		}
	}

	public function lookup_nonce(OkapiConsumer $consumer, $token, $nonce, $timestamp)
	{
		# First, see if it exists. Note, that old nonces are deleted
		# periodically by a cronjob.
		
		$exists = sqlValue("
			select 1
			from okapi_nonces
			where
				consumer_key = '".mysql_real_escape_string($consumer->key)."'
				and `key` = '".mysql_real_escape_string($nonce)."'
				and timestamp = '".mysql_real_escape_string($timestamp)."'
		", 0);
		if ($exists)
			return $nonce;
		
		# It didn't exist. We have to remember it.
		
		sql("
			insert into okapi_nonces (consumer_key, `key`, timestamp)
			values (
				'".mysql_real_escape_string($consumer->key)."',
				'".mysql_real_escape_string($nonce)."',
				'".mysql_real_escape_string($timestamp)."'
			);
		");
		return null;
	}

	public function new_request_token(OkapiConsumer $consumer, $callback)
	{
		if ((strpos($callback, "http://") === 0) ||
			(strpos($callback, "https://") === 0) ||
			$callback == "oob")
		{ /* ok */ }
		else { throw new BadRequest("oauth_callback should begin with http:// or https://, or should equal 'oob'."); }
		$token = new OkapiRequestToken(Okapi::generate_key(20), Okapi::generate_key(40),
			$consumer->key, $callback, null, Okapi::generate_key(8, true));
		sql("
			insert into okapi_tokens
				(`key`, secret, token_type, timestamp,
				user_id, consumer_key, verifier, callback)
			values (
				'".mysql_real_escape_string($token->key)."',
				'".mysql_real_escape_string($token->secret)."',
				'request',
				unix_timestamp(),
				null,
				'".mysql_real_escape_string($consumer->key)."',
				'".mysql_real_escape_string($token->verifier)."',
				".(($token->callback_url == 'oob')
					? "null"
					: "'".mysql_real_escape_string($token->callback_url)."'"
				)."
			);
		");
		return $token;
	}

	public function new_access_token(OkapiRequestToken $token, $consumer, $verifier)
	{
		if ($token->consumer_key != $consumer->key)
			throw new BadRequest("Request Token given is not associated with the Consumer who signed the request.");
		if (!$token->authorized_by_user_id)
			throw new BadRequest("Request Token given has not been authorized.");
		if ($token->verifier != $verifier)
			throw new BadRequest("Invalid verifier.");
		
		# Invalidate the Request Token.
		
		sql("
			delete from okapi_tokens
			where `key` = '".mysql_real_escape_string($token->key)."'
		");
		
		# In OKAPI, all Access Tokens are long lived. Therefore, we don't want
		# to generate a new one every time a Consumer wants it. We will check
		# if there is already an Access Token generated for this (Consumer, User)
		# pair and return it if there is.
		
		$rs = sql("
			select `key`, secret
			from okapi_tokens
			where
				token_type = 'access'
				and user_id = '".mysql_real_escape_string($token->authorized_by_user_id)."'
				and consumer_key = '".mysql_real_escape_string($consumer->key)."'
		");
		$row = sql_fetch_assoc($rs);
		mysql_free_result($rs);
		if ($row !== null)
		{
			# Use existing Access Token
			
			$access_token = new OkapiAccessToken($row['key'], $row['secret'],
				$consumer->key, $token->authorized_by_user_id);
		}
		else
		{
			# Generate a new Access Token.
			
			$access_token = new OkapiAccessToken(Okapi::generate_key(20), Okapi::generate_key(40),
				$consumer->key, $token->authorized_by_user_id);
			sql("
				insert into okapi_tokens
					(`key`, secret, token_type, timestamp, user_id, consumer_key)
				values (
					'".mysql_real_escape_string($access_token->key)."',
					'".mysql_real_escape_string($access_token->secret)."',
					'access',
					unix_timestamp(),
					'".mysql_real_escape_string($access_token->user_id)."',
					'".mysql_real_escape_string($consumer->key)."'
				);
			");
		}
		return $access_token;
	}
}