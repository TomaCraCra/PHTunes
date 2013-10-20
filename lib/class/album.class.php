<?php
/* vim:set tabstop=8 softtabstop=8 shiftwidth=8 noexpandtab: */
/**
 * Access Class
 *
 *
 * LICENSE: GNU General Public License, version 2 (GPLv2)
 * Copyright (c) 2001 - 2011 Ampache.org All Rights Reserved
 *
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License v2
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA  02111-1307, USA.
 *
 * @package	Ampache
 * @copyright	2001 - 2011 Ampache.org
 * @license	http://opensource.org/licenses/gpl-2.0 GPLv2
 * @link	http://www.ampache.org/
 */

/**
 * Album Class
 *
 * This is the class responsible for handling the Album object
 * it is related to the album table in the database.
 *
 * @package	Ampache
 * @copyright	2001 - 2011 Ampache.org
 * @license	http://opensource.org/licenses/gpl-2.0 GPLv2
 * @link	http://www.ampache.org/
 */
class Album extends database_object {

	/* Variables from DB */
	public $id;
	public $name;
	public $disk;
	public $year;
	public $prefix;
	public $mbid; // MusicBrainz ID

	public $full_name; // Prefix + Name, generated

	// cached information
	public $_songs=array();

	/**
	 * __construct
	 * Album constructor it loads everything relating
	 * to this album from the database it does not
	 * pull the album or thumb art by default or
	 * get any of the counts.
	 */
	public function __construct($id='') {

		if (!$id) { return false; }

		/* Get the information from the db */
		$info = $this->get_info($id);

		// Foreach what we've got
		foreach ($info as $key=>$value) {
			$this->$key = $value;
		}

		// Little bit of formatting here
		$this->full_name = trim(trim($info['prefix']) . ' ' . trim($info['name']));

		return true;

	} // constructor

	/**
	 * construct_from_array
	 * This is often used by the metadata class, it fills out an album object from a
	 * named array, _fake is set to true
	 */
	public static function construct_from_array($data) {

		$album = new Album(0);
		foreach ($data as $key=>$value) {
			$album->$key = $value;
		}

		// Make sure that we tell em it's fake
		$album->_fake = true;

		return $album;

	} // construct_from_array

	/**
	 * build_cache
	 * This takes an array of object ids and caches all of their information
	 * with a single query
	 */
	public static function build_cache($ids,$extra=false) {

		// Nothing to do if they pass us nothing
		if (!is_array($ids) OR !count($ids)) { return false; }

		$idlist = '(' . implode(',', $ids) . ')';

		$sql = "SELECT * FROM `album` WHERE `id` IN $idlist";
		$db_results = Dba::read($sql);

		while ($row = Dba::fetch_assoc($db_results)) {
			parent::add_to_cache('album',$row['id'],$row);
		}

		// If we're extra'ing cache the extra info as well
		if ($extra) {
			$sql = "SELECT COUNT(DISTINCT(`song`.`artist`)) AS `artist_count`, " .
				"COUNT(`song`.`id`) AS `song_count`, " .
				"`artist`.`name` AS `artist_name`, " .
				"`artist`.`prefix` AS `artist_prefix`, " .
				"`artist`.`id` AS `artist_id`, `song`.`album`" .
				"FROM `song` " .
				"INNER JOIN `artist` ON `artist`.`id`=`song`.`artist` " .
				"WHERE `song`.`album` IN $idlist GROUP BY `song`.`album`";

			$db_results = Dba::read($sql);

			while ($row = Dba::fetch_assoc($db_results)) {
				$art = new Art($row['album'], 'album');
				$art->get_db();
				$row['has_art'] = make_bool($art->raw);
				$row['has_thumb'] = make_bool($art->thumb);
				parent::add_to_cache('album_extra',$row['album'],$row);
			} // while rows
		} // if extra

		return true;

	} // build_cache

	/**
	 * _get_extra_info
	 * This pulls the extra information from our tables, this is a 3 table join, which is why we don't normally
	 * do it
	 */
	private function _get_extra_info() {

		if (parent::is_cached('album_extra',$this->id)) {
			return parent::get_from_cache('album_extra',$this->id);
		}

		$sql = "SELECT " .
			"COUNT(DISTINCT(`song`.`artist`)) AS `artist_count`, " .
			"COUNT(`song`.`id`) AS `song_count`, " .
			"`artist`.`name` AS `artist_name`, " .
			"`artist`.`prefix` AS `artist_prefix`, " .
			"`artist`.`id` AS `artist_id` " .
			"FROM `song` INNER JOIN `artist` " . 
			"ON `artist`.`id`=`song`.`artist` " .
			"WHERE `song`.`album`='$this->id' " .
			"GROUP BY `song`.`album`";
		$db_results = Dba::read($sql);

		$results = Dba::fetch_assoc($db_results);

		$art = new Art($this->id, 'album');
		$art->get_db();
		$results['has_art'] = make_bool($art->raw);
		$results['has_thumb'] = make_bool($art->thumb);

		parent::add_to_cache('album_extra',$this->id,$results);

		return $results;

	} // _get_extra_info

	/**
	 * get_songs
	 * gets the songs for this album takes an optional limit
	 * and an optional artist, if artist is passed it only gets
	 * songs with this album + specified artist
	 */
	public function get_songs($limit = 0,$artist='') {

		$results = array();

		$artist = Dba::escape($artist);

		$sql = "SELECT `id` FROM `song` WHERE `album`='$this->id' ";
		if ($artist) {
			$sql .= "AND `artist`='$artist'";
		}
		$sql .= "ORDER BY `track`, `title`";
		if ($limit) {
			$sql .= " LIMIT $limit";
		}
		$db_results = Dba::read($sql);

		while ($r = Dba::fetch_assoc($db_results)) {
			$results[] = $r['id'];
		}

		return $results;

	} // get_songs
        
        /**
	 * get_songs_withartist
	 * gets all the songs for this album with the artist name
	 */
	public function get_songs_with_artist() {
            $sql = "SELECT sg.id, sg.track, sg.title, sg.time, ar.name, sg.played, sg.year
                    FROM song sg
                    LEFT JOIN artist ar ON sg.artist = ar.id
                    WHERE sg.album = ".$this->id."
                    ORDER BY track";
            $result = Dba::read($sql);

            $i=0;
            $songs = array();

            while($song = Dba::fetch_assoc($result)) {
                $songs[$i]['id']     = $song['id'];
                $songs[$i]['track']  = $song['track'];
                $songs[$i]['title']  = $song['title'];
                $songs[$i]['time']   = $song['time'];
                $songs[$i]['name']   = $song['name'];
                $songs[$i]['year']   = $song['year'];
                $songs[$i]['played'] = $song['played'];

                $i++;
            }
            
            return $songs;
        }
        
        public static function get_last($nbMax = 20) {
            $sql = "SELECT album.id, 
                           album.name, 
                           album.prefix,
                           album.year, 
                           image.id AS imId,
                           image.mime
                    FROM `song`
                    LEFT JOIN album ON album.id = song.album
                    LEFT JOIN image ON (album.id = image.object_id AND object_type = 'album' AND image.size = 'original')
                    GROUP BY album
                    HAVING MAX(song.addition_time)
                    ORDER BY song.addition_time DESC
                    LIMIT ".$nbMax;
            
            $result = Dba::read($sql);

            $i=0;
            $albums = array();

            while($album = Dba::fetch_assoc($result)) {
                $albums[$i]['id']     = $album['id'];
                $albums[$i]['name']   = $album['name'];
                $albums[$i]['prefix'] = $album['prefix'];
                $albums[$i]['year']   = $album['year'];
                $albums[$i]['imId']   = $album['imId'];

                switch($album['mime']) {
                    case 'image/bmp': $albums[$i]['ext'] = 'bmp'; break;
                    case 'image/png': $albums[$i]['ext'] = 'png'; break;
                    default:          $albums[$i]['ext'] = 'jpg'; break;
                }

                $i++;
            }
            
            return $albums;
        }
        
        public static function get_all($artistId = null, $albumId = null) {

            //If artist filter
            $albums = array();
            if(is_numeric($artistId)) {
                
                $sql = "SELECT album FROM song WHERE artist = ".(int)$artistId;
                $result = Dba::read($sql);
                
                while($album = Dba::fetch_row($result)) { 
                    $albums[] = $album[0];
                }
                
                if(empty($albums)) {
                    return;
                }
            }

            $sql = "SELECT al.id, 
                           al.name, 
                           al.prefix, 
                           al.year, 
                           im.id AS imId,
                           im.mime
                    FROM album al
                    LEFT JOIN image im ON (al.id = im.object_id AND object_type = 'album' AND size = 'original') 
                    WHERE 1 = 1 ";
            
            if(is_numeric($albumId)) {
                $sql .= " AND al.id = ".(int)$albumId;
            }
            
            if(!empty($albums)) {
                $sql .= " AND al.id IN (".implode(', ', $albums).")";
            }

            $result = Dba::read($sql);

            $i=0;
            $albums = array();

            while($album = Dba::fetch_assoc($result)) {
                $albums[$i]['id']     = $album['id'];
                $albums[$i]['name']   = $album['name'];
                $albums[$i]['prefix'] = $album['prefix'];
                $albums[$i]['year']   = $album['year'];
                $albums[$i]['imId']   = $album['imId'];

                switch($album['mime']) {
                    case 'image/bmp': $albums[$i]['ext'] = 'bmp'; break;
                    case 'image/png': $albums[$i]['ext'] = 'png'; break;
                    default:          $albums[$i]['ext'] = 'jpg'; break;
                }

                $i++;
            }
            
            return $albums;
        }

	/**
	 * has_track
	 * This checks to see if this album has a track of the specified title
	 */
	public function has_track($title) {

		$title = Dba::escape($title);

		$sql = "SELECT `id` FROM `song` WHERE `album`='$this->id' AND `title`='$title'";
		$db_results = Dba::read($sql);

		$data = Dba::fetch_assoc($db_results);

		return $data;

	} // has_track

	/**
	 * format
	 * This is the format function for this object. It sets cleaned up
	 * album information with the base required
	 * f_link, f_name
	 */
	public function format() {

		$web_path = Config::get('web_path');

		/* Pull the advanced information */
		$data = $this->_get_extra_info();
		foreach ($data as $key=>$value) { $this->$key = $value; }

		/* Truncate the string if it's to long */
	  	$this->f_name		= truncate_with_ellipsis($this->full_name,Config::get('ellipse_threshold_album'));

		$this->f_name_link	= "<a href=\"$web_path/albums.php?action=show&amp;album=" . scrub_out($this->id) . "\" title=\"" . scrub_out($this->full_name) . "\">" . scrub_out($this->f_name);
		// If we've got a disk append it
		if ($this->disk) {
			$this->f_name_link .= " <span class=\"discnb disc" .$this->disk. "\">[" . _('Disk') . " " . $this->disk . "]</span>";
		}
		$this->f_name_link .="</a>";

		$this->f_link 		= $this->f_name_link;
		$this->f_title		= $this->full_name; // FIXME: Legacy?
		if ($this->artist_count == '1') {
			$artist = trim(trim($this->artist_prefix) . ' ' . trim($this->artist_name));
			$this->f_artist_name = $artist;
			$artist = scrub_out(truncate_with_ellipsis($artist), Config::get('ellipse_threshold_artist'));
			$this->f_artist_link = "<a href=\"$web_path/artists.php?action=show&amp;artist=" . $this->artist_id . "\" title=\"" . scrub_out($this->artist_name) . "\">" . $artist . "</a>";
			$this->f_artist = $artist;
		}
		else {
			$this->f_artist_link = "<span title=\"$this->artist_count " . _('Artists') . "\">" . _('Various') . "</span>";
			$this->f_artist = _('Various');
			$this->f_artist_name =  $this->f_artist;
		}

		if ($this->year == '0') {
			$this->year = "N/A";
		}

		$tags = Tag::get_top_tags('album',$this->id);
		$this->tags = $tags;

		$this->f_tags = Tag::get_display($tags,$this->id,'album');

	} // format

	/**
	 * get_random_songs
	 * gets a random number, and a random assortment of songs from this album
	 */
	function get_random_songs() {

		$sql = "SELECT `id` FROM `song` WHERE `album`='$this->id' ORDER BY RAND()";
		$db_results = Dba::read($sql);

		while ($r = Dba::fetch_row($db_results)) {
			$results[] = $r['0'];
		}

		return $results;

	} // get_random_songs

	/**
	 * update
	 * This function takes a key'd array of data and updates this object
	 * as needed, and then throws down with a flag
	 */
	public function update($data) {

		$year 		= $data['year'];
		$artist		= $data['artist'];
		$name		= $data['name'];
		$disk		= $data['disk'];
		$mbid		= $data['mbid'];

		$current_id = $this->id;

		if ($artist != $this->artist_id AND $artist) {
			// Update every song
			$songs = $this->get_songs();
			foreach ($songs as $song_id) {
				Song::update_artist($artist,$song_id);
			}
			$updated = 1;
			Catalog::clean_artists();
		}

		$album_id = Catalog::check_album($name,$year,$disk,$mbid);
		if ($album_id != $this->id) {
			if (!is_array($songs)) { $songs = $this->get_songs(); }
			foreach ($songs as $song_id) {
				Song::update_album($album_id,$song_id);
				Song::update_year($year,$song_id);
			}
			$current_id = $album_id;
			$updated = 1;
			Catalog::clean_albums();
		}

		if ($updated) {
			// Flag all songs
			foreach ($songs as $song_id) {
				Flag::add($song_id,'song','retag','Interface Album Update');
				Song::update_utime($song_id);
			} // foreach song of album
			Catalog::clean_stats();
		} // if updated


		return $current_id;

	} // update

	/**
	 * get_random_albums
	 * This returns a random number of albums from the catalogs
	 * this is used by the index to return some 'potential' albums to play
	 */
	public static function get_random_albums($count=6) {

		$sql = 'SELECT `id` FROM `album` ORDER BY RAND() LIMIT ' . ($count*2);
		$db_results = Dba::read($sql);

		while ($row = Dba::fetch_assoc($db_results)) {
			$art = new Art($row['id'], 'album');
			$art->get_db();
			if ($art->raw) {
				$results[] = $row['id'];
			}
		}

		if (count($results) < $count) { return false; }

		$results = array_slice($results, 0, $count);

		return $results;

	} // get_random_albums

} //end of album class
