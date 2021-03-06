<?php
/**
 * Asazuke 2
 */
namespace tomk79\pickles2\asazuke2;

/**
 * Execute Asazuke 2
 */
class execute{

	private $az;

	private $project_model;

	private $target_path_list = array();	//実行待ちURLの一覧
	private $done_url_count = 0;		//実行済みURLの数

	private $crawl_starttime = 0;//クロール開始時刻
	private $crawl_endtime = 0;//クロール終了時刻


	/**
	 * コンストラクタ
	 */
	public function __construct( $az ){
		$this->az = $az;
		$this->project_model = $this->az->factory_model_project();
		$this->project_model->load_project();
	}

	/**
	 * 処理の開始
	 */
	public function start(){
		@header( 'Content-type: text/plain; charset=UTF-8' );

		$project_model = $this->project_model;

		$this->msg( '---------- Asazuke 2 for Pickles 2 ----------' );
		$this->msg( 'Copyright (C)Tomoya Koyanagi, All rights reserved.' );
		$this->msg( '---------------------------------------------' );
		$this->msg( 'Process ID ['.getmypid().']' );
		$this->msg( 'Document root path => '.$project_model->get_path_docroot() );
		$this->msg( 'Start page path => '.$project_model->get_path_startpage() );
		$this->msg( 'Output directory path => '.$this->az->get_path_output_dir() );
		$this->msg( 'Accept HTML file max size => '.$project_model->get_accept_html_file_max_size() );
		$this->msg( 'crawl_max_url_number => '.$this->az->config()['crawl_max_url_number'] );
		if( !is_dir( $project_model->get_path_docroot() ) ){
			$this->error_log( 'Config error: Document root directory is NOT exists, or NOT a directory.' , __FILE__ , __LINE__ );
			return	$this->exit_process( false );
		}
		if( !is_dir( $this->az->get_path_output_dir() ) ){
			$this->error_log( 'Config error: Output directory is NOT exists, or NOT a directory.' , __FILE__ , __LINE__ );
			return	$this->exit_process( false );
		}
		if( !is_int( $this->az->config()['crawl_max_url_number'] ) ){
			$this->error_log( 'Config error: crawl_max_url_number is NOT a number.' , __FILE__ , __LINE__ );
			return	$this->exit_process( false );
		}

		#--------------------------------------
		#	ロック中か否かを判断
		if( !$this->lock() ){
			$error_msg = 'Asazuke 2 is locked.';
			$this->error_log( $error_msg , __FILE__ , __LINE__ );
			return	$this->exit_process( false );
		}

		#--------------------------------------
		#	ダウンロード先のパス内を一旦削除
		if( !$this->az->clear_output_files() ){
			$this->unlock();
			$error_msg = 'Failed to clear output files.';
			$this->error_log( $error_msg , __FILE__ , __LINE__ );
			return	$this->exit_process( false );
		}

		$this->msg( '--------------------------------------' );
		$this->crawl_starttime = time();
		$this->msg( '*** Start of Crawling --- '.$this->int2datetime( $this->crawl_starttime ) );
		$this->msg( '' );

		#--------------------------------------
		#	スタートページを登録
		$startpage = $project_model->get_path_startpage();
		$this->msg( 'set ['.$startpage.'] as the Startpage.' );

		$this->add_target_path( $startpage );
		unset( $startpage );

		// 対象のファイルをスキャンして、スクレイピング対象に追加
		$this->scan_starting_files($project_model);
		set_time_limit(30);


		// CSVの定義行を保存
		$this->save_executed_url_row(
			array(
				'url'=>'Path' ,
				'errors'=>'スクレイプエラー' ,
				'original_filesize'=>'オリジナルファイルサイズ' ,
				'extension'=>'拡張子' ,
				'title'=>'タイトル' ,
				'title:replace_pattern'=>'タイトル置換パターン名' ,
				'main_contents:pattern'=>'メインコンテンツ抽出パターン名' ,
				'sub_contents:pattern'=>'サブコンテンツ抽出パターン名' ,
				'replace_strings'=>'文字列置換パターン名' ,
				'dom_convert'=>'DOM置換パターン名' ,
				'time'=>'日時' ,
			)
		);

		#######################################
		// クロールの設定をログに残す
		$this->save_crawl_settings();


		// サイトマップを作成し始める
		$this->start_sitemap();

		while( 1 ){
			set_time_limit(0);

			#	注釈：	このwhileループは、URLの一覧($this->target_path_list)を処理する途中で、
			#			新しいURLがリストに追加される可能性があるため、
			#			これがゼロ件になるまで処理を継続する必要があるために、用意されたものです。

			$counter = $this->get_count_target_url();
			if( !$counter ){
				$this->msg( 'All URL are done!!' );
				break;
			}

			if( $this->is_request_cancel() ){
				//キャンセル要求を検知したらば、中断して抜ける。
				$cancel_message = 'This operation has been canceled.';
				$this->msg( $cancel_message );
				break;
			}

			foreach( $this->target_path_list as $url=>$url_property ){
				if( $this->is_request_cancel() ){
					//キャンセル要求を検知したらば、中断して抜ける。
					$cancel_message = 'This operation has been canceled.';
					$this->msg( $cancel_message );
					break 2;
				}

				$this->msg( '-----' );
				$this->msg( 'Executing ['.$url.']...' );
				$this->touch_lockfile();//ロックファイルを更新

				$URL_PROTOCOL = null;
				$URL_DOMAIN = null;
				if(preg_match( '/^([a-z0-9]+)\:\/\/(.+?)(\/.*)$/i' , $url , $url_info )){
					$URL_PROTOCOL = strtolower( $url_info[1] );
					$URL_DOMAIN = strtolower( $url_info[2] );
				}

				#	ダウンロード先のパスを得る
				$path_output_dir = $this->az->get_path_output_dir();
				if( $path_output_dir === false ){
					$this->error_log( 'ダウンロード先のディレクトリが不正です。' , __FILE__ , __LINE__ );
					$this->target_url_done( $url );
					return	$this->exit_process();
				}

				// $path_save_to = $project_model->url2localpath( $url , $url_property['post'] );
				$path_save_to = '/contents'.$url;
				$this->msg( 'save to ['.$path_save_to.']' );

				$fullpath_save_to = $path_output_dir.$path_save_to;
				$fullpath_save_to = str_replace( '\\' , '/' , $fullpath_save_to );
				$fullpath_savetmpfile_to = $path_output_dir.'/tmp_downloadcontent.tmp';

				$fullpath_from = $this->az->fs()->get_realpath($project_model->get_path_docroot().$url);

				clearstatcache();


				// オリジナルを、一時ファイルにコピー
				$original_filesize = filesize($fullpath_from);
				$this->msg( 'original file size : '.$original_filesize.' byte(s)' );
				if( !$this->az->fs()->copy( $fullpath_from, $fullpath_savetmpfile_to ) ){
					$this->error_log( 'クロール対象のファイル ['.$url.'] を一時ファイルに保存できませんでした。' , __FILE__ , __LINE__ );
				}

				clearstatcache();

				// オペレータを準備
				$obj_contents_operator = new operator_contents( $this->az, $this->project_model );
				$path_sitemap_csv = realpath( $this->az->get_path_output_dir() ).'/sitemaps/sitemap.csv';
				$obj_sitemap_operator = new operator_sitemap( $this->az, $this->project_model, $path_sitemap_csv );

				// ----------
				// スクレイピングしてサイトマップを追記
				$obj_sitemap_operator->scrape($url, $fullpath_savetmpfile_to);
				// ----------

				#--------------------------------------
				#	実際のあるべき場所へファイルを移動
				#		(=>コンテンツのスクレイピングを実施)
				$is_savefile = true;
				if( !is_file( $fullpath_savetmpfile_to ) ){
					$is_savefile = false;
				}
				if( $is_savefile ){
					clearstatcache();
					if( is_file( $fullpath_save_to ) ){
						if( !is_writable( $fullpath_save_to ) ){
							$this->error_log( 'コンテンツ設置先にファイルが存在し、書き込み権限がありません。' , __FILE__ , __LINE__ );
						}
					}elseif( is_dir( $fullpath_save_to ) ){
						$this->error_log( 'コンテンツ設置先がディレクトリです。' , __FILE__ , __LINE__ );
					}elseif( is_dir( dirname( $fullpath_save_to ) ) ){
						if( !is_writable( dirname( $fullpath_save_to ) ) ){
							$this->error_log( 'コンテンツ設置先にファイルは存在せず、親ディレクトリに書き込み権限がありません。' , __FILE__ , __LINE__ );
						}
					}else{
						if( !$this->az->fs()->mkdir_r( dirname( $fullpath_save_to ) ) || !is_dir( dirname( $fullpath_save_to ) ) ){
							$this->error_log( 'コンテンツ設置先ディレクトリの作成に失敗しました。' , __FILE__ , __LINE__ );
						}
					}

					clearstatcache();

					if( !$obj_contents_operator->scrape( $url, $fullpath_savetmpfile_to, $fullpath_save_to ) ){
						$this->error_log( 'コンテンツのスクレイピングに失敗しました。' , __FILE__ , __LINE__ );
					}
					if( !unlink($fullpath_savetmpfile_to) ){
						$this->error_log( '一時ファイルの削除に失敗しました。' , __FILE__ , __LINE__ );
					}

					clearstatcache();
					$fullpath_save_to = realpath( $fullpath_save_to );
					if( $fullpath_save_to === false ){
						$this->error_log( '保存先の realpath() を取得できませんでした。' , __FILE__ , __LINE__ );
					}
				}
				clearstatcache();
				if( is_file( $fullpath_savetmpfile_to ) ){
					@unlink( $fullpath_savetmpfile_to );
				}
				#	/ 実際のあるべき場所へファイルを移動
				#--------------------------------------

				// 結果報告を受け取る
				$result_cont = $obj_contents_operator->get_result();
				$result_sitemap = $obj_sitemap_operator->get_result();
				// var_dump($result_cont);
				// var_dump($result_sitemap);

				#--------------------------------------
				#	画面にメッセージを出力
				$this->msg( 'title: ['.@$result_sitemap['title'].']' );
				$this->msg( 'title:replace_pattern: ['.@$result_sitemap['title:replace_pattern'].']' );
				$this->msg( 'main contents pattern: ['.@$result_cont['main_contents:pattern'].']' );
				if( is_array(@$result_cont['sub_contents:pattern']) && count(@$result_cont['sub_contents:pattern']) ){
					$this->msg( 'sub contents pattern: ['.implode(', ', @$result_cont['sub_contents:pattern']).']' );
				}
				if( is_array(@$result_cont['replace_strings']) && count(@$result_cont['replace_strings']) ){
					$this->msg( 'replace strings: ['.implode(', ', @$result_cont['replace_strings']).']' );
				}
				#	/ 画面にメッセージを出力
				#--------------------------------------

				#--------------------------------------
				#	完了のメモを残す
				$this->target_url_done( $url );
				$this->save_executed_url_row(
					array(
						'url'=>$url ,
						'errors'=>@$result_cont['errors'] ,
						'original_filesize'=>$original_filesize ,
						'extension'=>$this->az->fs()->get_extension($url) ,
						'title'=>@$result_sitemap['title'] ,
						'title:replace_pattern'=>@$result_sitemap['title:replace_pattern'] ,
						'main_contents:pattern'=>@$result_cont['main_contents:pattern'] ,
						'sub_contents:pattern'=>@implode(', ', $result_cont['sub_contents:pattern']) ,
						'replace_strings'=>@implode(', ', $result_cont['replace_strings']) ,
						'dom_convert'=>@implode(', ', $result_cont['dom_convert']) ,
						'time'=>date('Y/m/d H:i:s') ,
					)
				);
				#	/ 完了のメモを残す
				#--------------------------------------

				clearstatcache();
				if( !is_file( $fullpath_save_to ) ){
					#	この時点でダウンロードファイルがあるべきパスに保存されていなければ、
					#	これ以降の処理は不要。次へ進む。
					$this->msg( '処理済件数 '.intval( $this->get_count_done_url() ).' 件.' );
					$this->msg( '残件数 '.count( $this->target_path_list ).' 件.' );

					$this->msg( '' );
					continue;
				}

				if( preg_match( '/\/$/' , $url ) ){
					#	スラッシュで終わってたら、ファイル名を追加
					if( strlen( $project_model->get_default_filename() ) ){
						$url .= $project_model->get_default_filename();
					}else{
						$url .= 'index.html';
					}
				}


				$this->msg( '処理済件数 '.intval( $this->get_count_done_url() ).' 件.' );
				$this->msg( '残件数 '.count( $this->target_path_list ).' 件.' );

				if( $this->get_count_done_url() >= $this->az->config()['crawl_max_url_number'] ){
					#	処理可能な最大URL数を超えたらおしまい。
					$message_string = 'URL count is OVER '.$this->az->config()['crawl_max_url_number'].'.';
					$this->msg( $message_string );
					break 2;
				}
				$this->msg( '' );
				continue;

			}

		}

		return $this->exit_process();
	}


	/**
	 * 対象ページをスキャンしてスタートページに登録する
	 */
	private function scan_starting_files( $project_model, $path = null ){
		if(!strlen($path)){
			$path = '';
		}
		$path_base = $project_model->get_path_docroot();
		if( !strlen($path_base) ){ return false; }
		if( !is_dir($path_base.$path) ){
			return false;
		}

		// スキャン開始
		$ls = $this->az->fs()->ls( $path_base.$path );
		foreach( $ls as $base_name ){
			set_time_limit(30);
			if( is_dir( $path_base.$path.$base_name ) ){
				// 再帰処理
				$this->scan_starting_files($project_model, $path.$base_name.'/');
			}elseif( is_file( $path_base.$path.$base_name ) ){
				$ext = $this->az->fs()->get_extension( $path_base.$path.$base_name );
				$target_path = '/'.$path.$base_name;
				switch( strtolower($ext) ){
					case 'html':
						$target_path = preg_replace( '/\/index\.html$/s', '/', $target_path );
						if( $this->add_target_path( $target_path ) ){
							$this->msg( 'set ['.$target_path.'] as the Startpage.' );
						}else{
							$this->msg( 'FAILD to add ['.$target_path.'] as the Startpage.' );
						}
						break;
					default:
						if( $this->add_target_path( $target_path ) ){
							$this->msg( 'set ['.$target_path.'] as the Startpage.' );
						}else{
							$this->msg( 'FAILD to add ['.$target_path.'] as the Startpage.' );
						}
						break;
				}
			}
		}
		set_time_limit(30);
		return true;
	}//scan_starting_files()



	#########################################################################################################################################################
	#	その他

	/**
	 * pathを処理待ちリストに追加
	 */
	private function add_target_path( $path , $option = array() ){
		$path = preg_replace( '/\/$/', '/index.html' , $path );

		#--------------------------------------
		#	要求を評価

		if( !preg_match( '/^\//' , $path ) ){ return false; }
			// 定形外のURLは省く
#		if( !preg_match( '/\.html$/' , $path ) ){ return false; }
			// ここで扱うのは、*.html のみ
		if( array_key_exists($path, $this->target_path_list) && is_array( $this->target_path_list[$path] ) ){ return false; }
			// すでに予約済みだったら省く

		$path_output_dir = $this->az->get_path_output_dir();
		if( is_dir( $path_output_dir.$path ) ){ return false; }
			// 既に保存済みだったら省く
		if( is_file( $path_output_dir.$path ) ){ return false; }
			// 既に保存済みだったら省く


		#--------------------------------------
		#	問題がなければ追加。
		$this->target_path_list[$path] = array();
		$this->target_path_list[$path]['path'] = $path;

		return	true;
	}

	/**
	 * 現在処理待ちのURL数を取得
	 */
	public function get_count_target_url(){
		return	count( $this->target_path_list );
	}
	/**
	 * URLが処理済であることを宣言
	 */
	private function target_url_done( $url ){
		unset( $this->target_path_list[$url] );
		$this->done_url_count ++;
		return	true;
	}
	/**
	 * 処理済URL数を取得
	 */
	public function get_count_done_url(){
		return	intval( $this->done_url_count );
	}

	/**
	 * ダウンロードしたURLの一覧に履歴を残す
	 */
	private function save_executed_url_row( $array_csv_line = array() ){
		$path_output_dir = realpath( $this->az->get_path_output_dir() );
		if( !is_dir( $path_output_dir ) ){ return false; }
		if( !is_dir( $path_output_dir.'/_logs' ) ){
			if( !$this->az->fs()->mkdir( $path_output_dir.'/_logs' ) ){
				return	false;
			}
		}

		$csv_charset = mb_internal_encoding();
		if( strlen( $this->az->config()['execute_list_csv_charset'] ) ){
			$csv_charset = $this->az->config()['execute_list_csv_charset'];
		}

		#--------------------------------------
		#	行の文字コードを調整
		foreach( $array_csv_line as $lineKey=>$lineVal ){
			if( mb_detect_encoding( $lineVal ) ){
				$array_csv_line[$lineKey] = mb_convert_encoding( $lineVal , mb_internal_encoding() , mb_detect_encoding( $lineVal ) );
			}
		}
		#	/ 行の文字コードを調整
		#--------------------------------------

		$csv_line = $this->az->fs()->mk_csv( array( $array_csv_line ) , array('charset'=>$csv_charset) );

		error_log( $csv_line , 3 , $path_output_dir.'/_logs/execute_list.csv' );
		$this->az->fs()->chmod( $path_output_dir.'/_logs/execute_list.csv' );

		return	true;
	}//save_executed_url_row();

	/**
	 * クロールの設定をログに残す
	 */
	private function save_crawl_settings(){
		// PicklesCrawler 0.4.2 追加
		$path_output_dir = realpath( $this->az->get_path_output_dir() );
		if( !is_dir( $path_output_dir ) ){ return false; }
		if( !is_dir( $path_output_dir.'/_logs' ) ){
			if( !$this->az->fs()->mkdir( $path_output_dir.'/_logs' ) ){
				return	false;
			}
		}

		$FIN = '';
		$FIN .= '[Project Info]'."\n";
		$FIN .= 'Start page URL: '.$this->project_model->get_path_startpage()."\n";
		$FIN .= 'Document root URL: '.$this->project_model->get_path_docroot()."\n";



		error_log( $FIN , 3 , $path_output_dir.'/_logs/settings.txt' );
		$this->az->fs()->chmod( $path_output_dir.'/_logs/settings.txt' );

		return	true;
	}//save_crawl_settings();

	/**
	 * サイトマップCSVを保存する系: 先頭
	 */
	private function start_sitemap(){
		$path_output_dir = realpath( $this->az->get_path_output_dir() );
		if( !is_dir( $path_output_dir ) ){ return false; }
		if( !is_dir( $path_output_dir.'/sitemaps' ) ){
			if( !$this->az->fs()->mkdir( $path_output_dir.'/sitemaps' ) ){
				return	false;
			}
		}

		$sitemap_definition = $this->az->get_sitemap_definition();
		$sitemap_key_list = array();
		foreach( $sitemap_definition as $row ){
			array_push( $sitemap_key_list , '* '.$row['key'] );

		}
		$LINE = '';
		$LINE .= $this->az->fs()->mk_csv(array($sitemap_key_list), array('charset'=>'UTF-8'));

		error_log( $LINE , 3 , $path_output_dir.'/sitemaps/sitemap.csv' );
		$this->az->fs()->chmod( $path_output_dir.'/sitemaps/sitemap.csv' );

		return	true;
	}

	/**
	 * 開始と終了の時刻を保存する
	 */
	private function save_start_and_end_datetime( $start_time , $end_time ){
		$path_output_dir = realpath( $this->az->get_path_output_dir() );
		$CONTENT = '';
		$CONTENT .= $this->int2datetime( $start_time );
		$CONTENT .= ' --- ';
		$CONTENT .= $this->int2datetime( $end_time );
		$result = $this->az->fs()->save_file( $path_output_dir.'/_logs/datetime.txt' , $CONTENT );
		return	$result;
	}

	/**
	 * エラーログを残す
	 */
	private function error_log( $msg , $file = null , $line = null ){
		$this->az->error_log( $msg , $file , $line );
		$this->msg( '[--ERROR!!--] '.$msg );
		return	true;
	}
	/**
	 * メッセージを出力する
	 */
	private function msg( $msg ){
		print	$msg."\n";
		flush();
		return	true;
	}

	/**
	 * プロセスを終了する
	 */
	private function exit_process( $is_unlock = true ){
		if( $is_unlock ){
			if( !$this->unlock() ){
				$this->error_log( 'FAILD to unlock!' , __FILE__ , __LINE__ );
			}
		}
		$this->crawl_endtime = time();
		$this->msg( '*** Exit --- '.$this->int2datetime( $this->crawl_endtime ) );
		$this->save_start_and_end_datetime( $this->crawl_starttime , $this->crawl_endtime );//←開始、終了時刻の記録
		return;
	}


	###################################################################################################################

	/**
	 * キャンセルリクエスト
	 */
	public function request_cancel(){
		$path = realpath( $this->az->get_path_output_dir() ).'/_logs/cancel.request';
		if( !is_dir( dirname( $path ) ) ){
			return	false;
		}
		if( is_file( $path ) && !is_writable( $path ) ){
			return	false;
		}elseif( !is_writable( dirname( $path ) ) ){
			return	false;
		}
		$this->az->fs()->save_file( $path , 'Cancel request: '.date('Y-m-d H:i:s')."\n" );
		return	true;
	}
	private function is_request_cancel(){
		$path = realpath( $this->az->get_path_output_dir() ).'/_logs/cancel.request';
		if( is_file( $path ) ){
			return	true;
		}
		return	false;
	}
	public function delete_request_cancel(){
		$path = realpath( $this->az->get_path_output_dir() ).'/_logs/cancel.request';
		if( !is_file( $path ) ){
			return	true;
		}elseif( !is_writable( $path ) ){
			return	false;
		}
		return	$this->az->fs()->rm( $path );
	}


	###################################################################################################################
	#	アプリケーションロック

	/**
	 * アプリケーションをロックする
	 */
	private function lock(){
		$lockfilepath = $this->get_path_lockfile();

		if( !@is_dir( dirname( $lockfilepath ) ) ){
			$this->az->fs()->mkdir_r( dirname( $lockfilepath ) );
		}

		#	PHPのFileStatusCacheをクリア
		clearstatcache();

		if( @is_file( $lockfilepath ) ){
			#	ロックファイルが存在したら、
			#	ファイルの更新日時を調べる。
			if( @filemtime( $lockfilepath ) > time() - (60*60) ){
				#	最終更新日時が 60分前 よりも未来ならば、
				#	このロックファイルは有効とみなす。
				return	false;
			}
		}

		$result = $this->az->fs()->save_file( $lockfilepath , 'This lockfile created at: '.date( 'Y-m-d H:i:s' , time() ).'; Process ID: ['.getmypid().'];'."\n" );
		return	$result;
	}

	/**
	 * ロックファイルの更新日時を更新する。
	 */
	private function touch_lockfile(){
		$lockfilepath = $this->get_path_lockfile();
		clearstatcache();
		touch( $lockfilepath );
		return	true;
	}

	/**
	 * アプリケーションロックを解除する
	 */
	private function unlock(){
		$lockfilepath = $this->get_path_lockfile();
		clearstatcache();
		return	$this->az->fs()->rm( $lockfilepath );
	}

	/**
	 * ロックファイルのパスを返す
	 */
	private function get_path_lockfile(){
		return $this->az->fs()->get_realpath( $this->az->get_path_output_dir().'/crawl.lock' );
	}

	/**
	 * UNIXタイムスタンプの値を、datetime型に変換
	 * 
	 * @param int $time UNIXタイムスタンプ
	 * @return string datetime型(YYYY-MM-DD HH:ii:ss) の文字列
	 */
	private function int2datetime( $time ){
		return	date( 'Y-m-d H:i:s' , $time );
	}

}
