<?php

class TwitchAutomator {

	public $data_cache 		= [];

	public $json = [];

	public $errors = [];
	public $info = [];

	const NOTIFY_GENERIC = 1;
	const NOTIFY_DOWNLOAD = 2;
	const NOTIFY_ERROR = 4;
	const NOTIFY_GAMECHANGE = 8;

	public $notify_level = NOTIFY_GENERIC && NOTIFY_DOWNLOAD && NOTIFY_ERROR && NOTIFY_GAMECHANGE;

	public function basename( $data ){

		$data_id = $data['data'][0]['id'];
		$data_title = $data['data'][0]['title'];
		$data_started = $data['data'][0]['started_at'];
		$data_game_id = $data['data'][0]['game_id'];
		$data_username = $data['data'][0]['user_name'];

		return $data_username . '_' . $data_id . '_' . str_replace(':', '_', $data_started);

	}

	public function getDateTime(){
		date_default_timezone_set('UTC');
		return date("Y-m-d\TH:i:s\Z");
		// return time();
	}

	public function jsonLoad(){

		if( !$this->data_cache ){
			$this->errors[] = 'No JSON cache when loading';
			return false;
		}

		$basename = $this->basename( $this->data_cache );

		if( !file_exists( TwitchConfig::cfg('vod_folder') . '/' . $basename . '.json') ){
			$this->errors[] = 'No JSON file when loading';
			$this->json = [];
			return;
		}

		$json = json_decode( file_get_contents( TwitchConfig::cfg('vod_folder') . '/' . $basename . '.json' ), true );

		if(!$json || $json == null) $json = [];

		$this->json = $json;

		return true;

	}

	public function jsonSave(){

		if( !$this->data_cache ){
			$this->errors[] = 'No JSON cache when saving';
			return false;
		}

		$basename = $this->basename( $this->data_cache );

		file_put_contents( TwitchConfig::cfg('vod_folder') . '/' . $basename . '.json', json_encode( $this->json ) );

		return true;

	}
	
	public function notify( $body, $title, $notification_type = self::NOTIFY_GENERIC ){

		$headers = "From: " . TwitchConfig::cfg('notify_from') . "\r\n";
		$headers .= "Content-Type: text/html; charset=UTF-8\r\n";

		$body = '<h1>' . $title . '</h1>' . $body;

		if($this->data_cache){
			$body .= '<pre>' . print_r( $this->data_cache, true ) . '</pre>';
		}

		if( sizeof($this->errors) > 0 ){

			$body .= '<h3>Errors</h3>';

			$body .= '<ul class="errors">';
			foreach ($this->errors as $k => $v) {
				$body .= '<li>' . $v . '</li>';
			}
			$body .= '</ul>';

		}

		if( sizeof($this->info) > 0 ){

			$body .= '<h3>Info</h3>';

			$body .= '<ul class="info">';
			foreach ($this->info as $k => $v) {
				$body .= '<li>' . $v . '</li>';
			}
			$body .= '</ul>';

		}

		if( TwitchConfig::cfg('notify_to') ){
			mail(TwitchConfig::cfg('notify_to'), TwitchConfig::cfg('app_name') . ' - ' . $title, $body, $headers);
		}else{
			file_put_contents('logs/' . date("Y-m-d.H_i_s") . '.html', $body);
		}

	}

	public function cleanup( $streamer_name ){

		$vods = glob( TwitchConfig::cfg('vod_folder') . "/" . $streamer_name . "_*.mp4");

		$total_size = 0;

		foreach ($vods as $v) {
			$total_size += filesize($v);
		}

		$gb = $total_size / 1024 / 1024 / 1024;

		$this->info[] = 'Total filesize for ' . $streamer_name . ': ' . $gb;

		if( sizeof($vods) > TwitchConfig::cfg('vods_to_keep') || $gb > TwitchConfig::cfg('storage_per_streamer') ){
			
			$this->notify('', ' (cleanup: ' . $vods[0] . ', size: ' . $gb . 'GB)');

			$basename = substr( $vods[0], 0, strlen($vods[0])-4 );
			
			TwitchHelper::log( TwitchHelper::LOG_INFO, "Cleanup " . $basename);

			unlink(sprintf('%s.mp4', $basename));
			unlink(sprintf('%s.json', $basename));
			unlink(sprintf('%s-llc-edl.csv', $basename)); // losslesscut
			
		}

	}

	public function handle( $data ){

		TwitchHelper::log( TwitchHelper::LOG_DEBUG, "Handle called");

		$data_id = $data['data'][0]['id'];
		// $data_title = $data['data'][0]['title'];
		// $data_started = $data['data'][0]['started_at'];
		// $data_game_id = $data['data'][0]['game_id'];
		// $data_username = $data['data'][0]['user_name'];

		$this->data_cache = $data;

		if( !$data_id ){

			$this->end( $data );

		}else{

			$basename = $this->basename( $data );
			
			if( file_exists( TwitchConfig::cfg('vod_folder') . '/' . $basename . '.json') ){

				if( !file_exists( TwitchConfig::cfg('vod_folder') . '/' . $basename . '.ts') ){

					$this->notify($basename, 'VOD JSON EXISTS BUT NOT VIDEO', self::NOTIFY_ERROR);

					$this->download( $data );

				}else{

					$this->updateGame( $data );

				}

			}else{

				$this->download( $data );

			}

		}

	}

	/**
	 * TODO: move this to helper?
	 */
	public function getGameName( $id ){

		if( $id == 0 ){
			$this->errors[] = 'Game ID is 0';
			return false;
		}

		$game_db = json_decode( file_get_contents( 'config/games_v2.json' ), true );

		if( $game_db[ $id ] ){
			$this->errors[] = 'Game is in database';
			return $game_db[ $id ]['name'];
		}

		TwitchHelper::log( TwitchHelper::LOG_DEBUG, "Game id " . $id . " not in cache, fetching..." );

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, 'https://api.twitch.tv/helix/games?id=' . $id);
		curl_setopt($ch, CURLOPT_HTTPHEADER, [
			'Authorization: Bearer ' . TwitchHelper::getAccessToken(),
		    'Client-ID: ' . TwitchConfig::cfg('api_client_id')
		]);

		curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);

		$server_output = curl_exec($ch);

		curl_close ($ch);

		$json = json_decode( $server_output, true );

		$game_data = $json["data"][0];

		if( $game_data ){

			$game = [
				"name" => $game_data["name"],
				"box_art_url" => $game_data["box_art_url"],
				"added" => time()
			];

			$game_db[ $id ] = $game;

			// $game_db[ $id ] = $game_data["name"];

			file_put_contents( 'config/games_v2.json', json_encode( $game_db ) );

			TwitchHelper::log( TwitchHelper::LOG_INFO, "New game saved to cache: " . $game["name"]);

			return $game["name"];

		}

		$this->errors[] = 'No game found for ' . $id;

		TwitchHelper::log( TwitchHelper::LOG_ERROR, "Couldn't match game for " . $id . " | " . $server_output );

		return false;

		// print_r($server_output);
		// print_r($info);

	}

	public function parseTwitchDuration( $text ){

		preg_match('/([0-9]+)h/', $text, $hours_match);
		preg_match('/([0-9]+)m/', $text, $minutes_match);
		preg_match('/([0-9]+)s/', $text, $seconds_match);

		$total_seconds = 0;

		if($seconds_match[1]) $total_seconds += $seconds_match[1];
		if($minutes_match[1]) $total_seconds += $minutes_match[1] * 60;
		if($hours_match[1]) $total_seconds += $hours_match[1] * 60 * 60;

		return $total_seconds;

	}

	public function updateGame( $data ){

		$data_id 			= $data['data'][0]['id'];
		$data_started 		= $data['data'][0]['started_at'];
		$data_game_id 		= $data['data'][0]['game_id'];
		$data_username 		= $data['data'][0]['user_name'];
		$data_viewer_count 	= $data['data'][0]['viewer_count'];
		$data_title 		= $data['data'][0]['title'];

		$basename = $this->basename( $data );

		// file_put_contents( 'vods/' . $basename . '.vod', "\n" . time() . ':' . $data_game_id, FILE_APPEND );

		$this->jsonLoad();

		// json format
	
		// full json data
		$this->json['meta'] = $data;
		
		// full datetime-stamp of stream start
		$this->json['started_at'] = $data_started;
		
		if(!$this->json['games']){
			$this->json['games'] = [];
		}

		// fetch game name from either cache or twitch
		$game_name = $this->getGameName( $data_game_id );

		// game structure
		$this->json['games'][] = [
			'time' 			=> $this->getDateTime(),
			'game_id' 		=> $data_game_id,
			'game_name'		=> $game_name,
			'viewer_count' 	=> $data_viewer_count,
			'title'			=> $data_title
		];

		$this->jsonSave();

		// file_put_contents( 'vods/' . $basename . '.json', json_encode( $json ) );
		
		//$game_name = $this->games[$data_game_id] ?: $data_game_id;

		$this->notify('', '[' . $data_username . '] [game update: ' . $game_name . ']', self::NOTIFY_GAMECHANGE);

		TwitchHelper::log( TwitchHelper::LOG_INFO, "Game updated on " . $data_username . " to " . $game_name);

	}

	public function end(){

		$this->notify('', '[stream end]', self::NOTIFY_DOWNLOAD);

	}

	public function download( $data, $tries = 0 ){

		$data_id = $data['data'][0]['id'];
		$data_title = $data['data'][0]['title'];
		$data_started = $data['data'][0]['started_at'];
		$data_game_id = $data['data'][0]['game_id'];
		$data_username = $data['data'][0]['user_name'];

		if( !$data_id ){
			$this->errors[] = 'No data id for download';
			$this->notify($data, 'NO DATA SUPPLIED FOR DOWNLOAD, TRY #' . $tries, self::NOTIFY_ERROR);
			throw new Exception('No data supplied');
			return;
		}

		// $this->notify('', '[' . $data_username . '] [prepare download]');

		$stream_url = 'twitch.tv/' . $data_username;

        $basename = $this->basename( $data );
        
        $streamer = TwitchConfig::getStreamer( $data_username );

		// check matched title
		if( $streamer && $streamer['match'] ){

			$match = false;

			$this->notify($basename, 'Check keyword matches for user ' . json_encode( $streamer ), self::NOTIFY_GENERIC);
			TwitchHelper::log( TwitchHelper::LOG_INFO, "Check keyword matches for " . $basename);

			foreach( $streamer['match'] as $m ){
				if( strpos( strtolower($data_title), $m ) !== false ){
					$match = true;
					break;
				}
			}

			if(!$match){
				$this->notify($basename, 'Cancel download because stream title does not contain keywords', self::NOTIFY_GENERIC);
				TwitchHelper::log( TwitchHelper::LOG_WARNING, "Cancel download of " . $basename . " due to missing keywords");
				return;
			}

		}

		// in progress
		TwitchHelper::log( TwitchHelper::LOG_INFO, "Update game for " . $basename);
		$this->updateGame( $data );

		// download notification
		$this->notify($basename, '[' . $data_username . '] [download]', self::NOTIFY_DOWNLOAD);

		/*
		$capture_filename = 'vods/' . $basename . '.ts';

		$cmd = 'streamlink --hls-live-restart --hls-live-edge 99999 --hls-segment-threads 5 --twitch-disable-hosting -o ' . escapeshellarg($capture_filename) . ' ' . $stream_url . ' ' . escapeshellarg($this->stream_quality);

		$output_download = exec( $cmd );
		*/
	
		// capture with streamlink
		$capture_filename = $this->capture( $data );

		// error handling if nothing got downloaded
		if( !file_exists( $capture_filename ) ){

			TwitchHelper::log( TwitchHelper::LOG_WARNING, "Panic handler for " . $basename);

			if( $tries >= TwitchConfig::cfg('download_retries') ){
				$this->errors[] = 'Giving up on downloading, too many tries';
				$this->notify($basename, 'GIVING UP, TOO MANY TRIES', self::NOTIFY_ERROR);
				// unlink( 'vods/' . $basename . '.json' );
				rename( TwitchConfig::cfg('vod_folder') . '/' . $basename . '.json', TwitchConfig::cfg('vod_folder') . '/' . $basename . '.json.broken' );
				throw new Exception('Too many tries');
				return;
			}

			$this->errors[] = 'Error when downloading, retrying';

			$this->info[] = 'Capture name: ' . $capture_filename;

			// $this->errors[] = $cmd;

			$this->notify($basename, 'MISSING DOWNLOAD, TRYING AGAIN (#' . $tries . ')', self::NOTIFY_ERROR);

			sleep(15);

			$this->download( $data, $tries + 1 );

			return;

		}

		// timestamp
		TwitchHelper::log( TwitchHelper::LOG_INFO, "Add end timestamp for " . $basename);
		$this->jsonLoad();
		$this->json['ended_at'] = $this->getDateTime();
		$this->jsonSave();

		sleep(60);



		// convert notify
		$this->notify($basename, '[' . $data_username . '] [convert]', self::NOTIFY_DOWNLOAD);
		
		// convert with ffmpeg
		$converted_filename = $this->convert( $basename );

		sleep(10);

		

		// $id3_data = $getID3->analyze($converted_filename);

		// remove ts if both files exist
		if( file_exists( $capture_filename ) && file_exists( $converted_filename ) ){

			$getID3 = new getID3;

			$id3_data = null;

			try {
				$id3_data = $getID3->analyze($converted_filename);
			} catch (Exception $e) {
				$this->notify($basename, 'Error with id3 analyzer' . $e, self::NOTIFY_ERROR);
			}

			if( !$id3_data || !$id3_data['playtime_string'] ){

				$this->errors[] = 'Missing mp4 length';

				$this->notify($basename, 'MISSING MP4 LENGTH', self::NOTIFY_ERROR);

			}else{

				unlink( $capture_filename );

			}

		}else{

			$this->errors[] = 'Video files are missing';

			$this->notify($basename, 'MISSING FILES', self::NOTIFY_ERROR);

		}

		TwitchHelper::log( TwitchHelper::LOG_INFO, "Add segments to " . $basename);
		$this->jsonLoad();
		if(!$this->json['segments']) $this->json['segments'] = [];
		$this->json['segments'][] = basename($converted_filename);
		$this->jsonSave();

		TwitchHelper::log( TwitchHelper::LOG_INFO, "Cleanup old VODs for " . $data_username);
		$this->cleanup( $data_username );

		$this->notify($basename, '[' . $data_username . '] [end]', self::NOTIFY_DOWNLOAD);



		// metadata stuff
		sleep(60 * 5);

		TwitchHelper::log( TwitchHelper::LOG_INFO, "Do metadata on " . $basename);

		$vodclass = new TwitchVOD();
		$vodclass->load( TwitchConfig::cfg('vod_folder') . '/' . $basename . '.json');

		$vodclass->saveLosslessCut();
		$vodclass->matchTwitchVod();
		$vodclass->saveJSON();
		
		if( TwitchConfig::cfg('download_chat') && $vodclass->twitch_vod_id ){
			TwitchHelper::log( TwitchHelper::LOG_INFO, "Auto download chat on " . $basename);
			$vodclass->downloadChat();
		}

		TwitchHelper::log( TwitchHelper::LOG_INFO, "All done for " . $basename);

	}

	public function capture( $data ){

		$data_id = $data['data'][0]['id'];
		$data_title = $data['data'][0]['title'];
		$data_started = $data['data'][0]['started_at'];
		$data_game_id = $data['data'][0]['game_id'];
		$data_username = $data['data'][0]['user_name'];

		if(!$data_id){
			$this->errors[] = 'ID not supplied for capture';
			return false;
		}
		
		$stream_url = 'twitch.tv/' . $data_username;

		$basename = $this->basename( $data );

		$capture_filename = TwitchConfig::cfg('vod_folder') . '/' . $basename . '.ts';

		if( TwitchConfig::cfg('pipenv') ){
			$cmd = 'pipenv run streamlink';
		}else{
			$cmd = TwitchConfig::cfg('bin_dir') . '/streamlink';
		}

		$cmd .= ' --hls-live-restart'; // start recording from start of stream, though twitch doesn't support this
		$cmd .= ' --hls-live-edge 99999';
		$cmd .= ' --hls-segment-threads 5';
		$cmd .= ' --twitch-disable-hosting'; // disable channel hosting
		$cmd .= ' -o ' . escapeshellarg($capture_filename); // output file
		$cmd .= ' ' . escapeshellarg($stream_url) . ' ' . escapeshellarg( TwitchConfig::cfg('stream_quality') ); // twitch url and quality

		$this->info[] = 'Streamlink cmd: ' . $cmd;

		TwitchHelper::log( TwitchHelper::LOG_INFO, "Starting capture with filename " . $basename);
		
		$capture_output = shell_exec( $cmd );

		$this->info[] = 'Streamlink output: ' . $capture_output;

		// download with youtube-dl if streamlink fails
		if( strpos($capture_output, '410 Client Error') !== false ){
			
			$this->notify($basename, '410 Error', self::NOTIFY_ERROR);
			// return false;
			
			if( TwitchConfig::cfg('pipenv') ){
				$cmd = 'pipenv run youtube-dl';
			}else{
				$cmd = TwitchConfig::cfg('bin_dir') . '/youtube-dl';
			}
			
			$cmd .= ' --hls-use-mpegts'; // use ts instead of mp4
			$cmd .= ' --no-part';
			$cmd .= ' -o ' . escapeshellarg($capture_filename); // output file
			$cmd .= ' -f ' . escapeshellarg( implode('/', explode(',', TwitchConfig::cfg('stream_quality') ) ) ); // format
			$cmd .= ' ' . escapeshellarg($stream_url);

			$this->info[] = 'Youtube-dl cmd: ' . $cmd;

			$capture_output = shell_exec( $cmd );

			$this->info[] = 'Youtube-dl output: ' . $capture_output;
			

			// exit(500);
		} 

		return $capture_filename;

	}

	/**
	 * Mux .ts to .mp4, for better compatibility
	 */
	public function convert( $basename ){

		$capture_filename 	= TwitchConfig::cfg('vod_folder') . '/' . $basename . '.ts';

		$converted_filename = TwitchConfig::cfg('vod_folder') . '/' . $basename . '.mp4';

		$int = 1;

		while( file_exists( $converted_filename ) ){
			$this->errors[] = 'File exists, making a new name';
			$converted_filename = TwitchConfig::cfg('vod_folder') . '/' . $basename . '-' . $int . '.mp4';
			$int++;
		}

		$cmd = TwitchConfig::cfg('ffmpeg_path');
		$cmd .= ' -i ' . escapeshellarg($capture_filename);
		$cmd .= ' -codec copy'; // use same codec
		$cmd .= ' -bsf:a aac_adtstoasc'; // fix audio sync in ts
		$cmd .= ' ' . escapeshellarg($converted_filename);
		
		$this->info[] = 'ffmpeg cmd: ' . $cmd;

		TwitchHelper::log( TwitchHelper::LOG_INFO, "Starting conversion of " . $capture_filename);

		$output_convert = shell_exec( $cmd );

		TwitchHelper::log( TwitchHelper::LOG_INFO, "Finished conversion of " . $capture_filename);

		$this->info[] = 'ffmpeg output: ' . $output_convert;
		
		return $converted_filename;

	}

	public function sub( $streamer_name ){

		/**
		 * TODO: Fix this
		 */
		/*
		 if( !TwitchConfig::getStreamers()[$streamer_name] ) {
			$this->notify('Streamer not found: ' . $streamer_name, '[' . $streamer_name . '] [subscribing error]', self::NOTIFY_ERROR);
			throw new Exception('Streamer not found: ' . $streamer_name);
			return false;
		}
		*/

		TwitchHelper::log( TwitchHelper::LOG_INFO, "Calling subscribe for " . $streamer_name);

		$streamer_id = TwitchHelper::getChannelId($streamer_name);

		if( !$streamer_id ) {
			$this->notify('Streamer ID not found for: ' . $streamer_name, '[' . $streamer_name . '] [subscribing error]', self::NOTIFY_ERROR);
			throw new Exception('Streamer ID not found for: ' . $streamer_name);
			return false;
		}

		$url = 'https://api.twitch.tv/helix/webhooks/hub';
		$method = 'POST';

		$data = [
			'hub.callback' => TwitchConfig::cfg('hook_callback'),
			'hub.mode' => 'subscribe',
			'hub.topic' => 'https://api.twitch.tv/helix/streams?user_id=' . $streamer_id,
			'hub.lease_seconds' => TwitchConfig::cfg('sub_lease')
		];

		// print_r( $data );

		$data_string = json_encode($data);

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
		curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
		curl_setopt($ch, CURLOPT_HTTPHEADER, [
		    'Content-Type: application/json',
			'Content-Length: ' . strlen($data_string),
			'Authorization: Bearer ' . TwitchHelper::getAccessToken(),
		    'Client-ID: ' . TwitchConfig::cfg('api_client_id')
		]);

		// curl_setopt($ch, CURLOPT_HEADER, TRUE);
		// curl_setopt($ch, CURLOPT_NOBODY, TRUE); // remove body
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);

		$server_output = curl_exec($ch);
		$info = curl_getinfo($ch);

		$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

		curl_close($ch);

		if( $http_code == 202 ){

			TwitchHelper::log( TwitchHelper::LOG_INFO, "Successfully subscribed to " . $streamer_name);

			$this->notify($server_output, '[' . $streamer_name . '] [subscribing]', self::NOTIFY_GENERIC);

			return true;

		}else{

			TwitchHelper::log( TwitchHelper::LOG_ERROR, "Failed to subscribe to " . $streamer_name . " | " . $server_output . " | HTTP " . $http_code );
			
			return $server_output;

		}

		// print_r($server_output);
		// print_r($info);

		

		// return json_decode( $server_output, true );

	}

	public function getSubs(){

		TwitchHelper::log( TwitchHelper::LOG_INFO, "Requesting subscriptions list");

		// webhook list
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, 'https://api.twitch.tv/helix/webhooks/subscriptions');
		curl_setopt($ch, CURLOPT_HTTPHEADER, [
			'Authorization: Bearer ' . TwitchHelper::getAccessToken(),
		    'Client-ID: ' . TwitchConfig::cfg('api_client_id')
		]);
		
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);

		$server_output = curl_exec($ch);

		curl_close ($ch);

		$json = json_decode( $server_output, true );

		return $json;

	}

}