<?php
defined( 'ABSPATH' ) or exit();


/**
 * Adds email support for events
 */
class EventRocket_EventEmail
{

    var $post_id;
    var $post;
    var $body;
    var $subject;
    var $ics;

    /**
	 * setup the object for generating everything needed
	 */
	public function __construct( $event_id, $subject = null, $body_add = null ) {
        $this->post_id = $event_id;
        $this->post = get_post( $event_id );
        $this->subject = $subject ? $subject : $this->post->post_title;
        $this->ics = $this->generate_ics( $this->post );

        // wordpress requires that you save the file to disk, lets put in uploads folder
        // we re-write every time in case teh event has changed since last use
        file_put_contents( WP_CONTENT_DIR . '/uploads/event-' . $this->post_id . '.ics', $this->ics );
    }

    private function set_body( $body = null ){
        $this->body = $body;
    }

    public function send( $to ) {
        wp_mail( $to, $this->subject, $this->body, '', WP_CONTENT_DIR . '/uploads/event_' . $this->post_id . '.ics' );
    }

    // This has been copied from the events calendar, because there is no way to grab the raw file
    private function generate_ics( $post ){
        $tec         = Tribe__Events__Main::instance();
		$events      = '';
		$blogHome    = get_bloginfo( 'url' );
		$blogName    = get_bloginfo( 'name' );

		$events_posts   = array();
		$events_posts[] = $post;

		$event_ids = wp_list_pluck( $events_posts, 'ID' );

		foreach ( $events_posts as $event_post ) {
			// add fields to iCal output
			$item = array();

			$full_format = 'Ymd\THis\Z';
			$time = (object) array(
				'start' => self::wp_strtotime( $event_post->EventStartDate ),
				'end' => self::wp_strtotime( $event_post->EventEndDate ),
				'modified' => self::wp_strtotime( $event_post->post_modified ),
				'created' => self::wp_strtotime( $event_post->post_date ),
			);

			if ( 'yes' == get_post_meta( $event_post->ID, '_EventAllDay', true ) ) {
				$type = 'DATE';
				$format = 'Ymd';
			} else {
				$type = 'DATE-TIME';
				$format = $full_format;
			}

			$tzoned = (object) array(
				'start' => date( $format, $time->start ),
				'end' => date( $format, $time->end ),
				'modified' => date( $full_format, $time->modified ),
				'created' => date( $full_format, $time->created ),
			);

			if ( 'DATE' === $type ){
				$item[] = "DTSTART;VALUE=$type:" . $tzoned->start;
				$item[] = "DTEND;VALUE=$type:" . $tzoned->end;
			} else {
				$item[] = 'DTSTART:' . $tzoned->start;
				$item[] = 'DTEND:' . $tzoned->end;
			}

			$item[] = 'DTSTAMP:' . date( $full_format, time() );
			$item[] = 'CREATED:' . $tzoned->created;
			$item[] = 'LAST-MODIFIED:' . $tzoned->modified;
			$item[] = 'UID:' . $event_post->ID . '-' . $time->start . '-' . $time->end . '@' . parse_url( home_url( '/' ), PHP_URL_HOST );
			$item[] = 'SUMMARY:' . str_replace( array( ',', "\n", "\r", "\t" ), array( '\,', '\n', '', '\t' ), html_entity_decode( strip_tags( $event_post->post_title ), ENT_QUOTES ) );
			$item[] = 'DESCRIPTION:' . str_replace( array( ',', "\n", "\r", "\t" ), array( '\,', '\n', '', '\t' ), html_entity_decode( strip_tags( $event_post->post_content ), ENT_QUOTES ) );
			$item[] = 'URL:' . get_permalink( $event_post->ID );

			// add location if available
			$location = $tec->fullAddressString( $event_post->ID );
			if ( ! empty( $location ) ) {
				$str_location = str_replace( array( ',', "\n" ), array( '\,', '\n' ), html_entity_decode( $location, ENT_QUOTES ) );

				$item[] = 'LOCATION:' .  $str_location;
			}

			// add geo coordinates if available
			if ( class_exists( 'Tribe__Events__Pro__Geo_Loc' ) ) {
				$long = Tribe__Events__Pro__Geo_Loc::instance()->get_lng_for_event( $event_post->ID );
				$lat  = Tribe__Events__Pro__Geo_Loc::instance()->get_lat_for_event( $event_post->ID );
				if ( ! empty( $long ) && ! empty( $lat ) ) {
					$item[] = sprintf( 'GEO:%s;%s', $long, $lat );

					$str_title = str_replace( array( ',', "\n" ), array( '\,', '\n' ), html_entity_decode( tribe_get_address( $event_post->ID ), ENT_QUOTES ) );

					if ( ! empty( $str_title ) && ! empty( $str_location ) ) {
						$item[] =
							'X-APPLE-STRUCTURED-LOCATION;VALUE=URI;X-ADDRESS=' . str_replace( '\,', '', trim( $str_location ) ) . ';' .
							'X-APPLE-RADIUS=500;' .
							'X-TITLE=' . trim( $str_title ) . ':geo:' . $long . ',' . $lat;
					}
				}
			}

			// add categories if available
			$event_cats = (array) wp_get_object_terms( $event_post->ID, Tribe__Events__Main::TAXONOMY, array( 'fields' => 'names' ) );
			if ( ! empty( $event_cats ) ) {
				$item[] = 'CATEGORIES:' . html_entity_decode( join( ',', $event_cats ), ENT_QUOTES );
			}

			// add featured image if available
			if ( has_post_thumbnail( $event_post->ID ) ) {
				$thumbnail_id        = get_post_thumbnail_id( $event_post->ID );
				$thumbnail_url       = wp_get_attachment_url( $thumbnail_id );
				$thumbnail_mime_type = get_post_mime_type( $thumbnail_id );
				$item[]              = apply_filters( 'tribe_ical_feed_item_thumbnail', sprintf( 'ATTACH;FMTTYPE=%s:%s', $thumbnail_mime_type, $thumbnail_url ), $event_post->ID );
			}

			// add organizer if available
			$organizer_email = tribe_get_organizer_email( $event_post->ID );
			if ( $organizer_email ) {
				$organizer_name = tribe_get_organizer( $event_post->ID );
				if ( $organizer_name ) {
					$item[] = sprintf( 'ORGANIZER;CN=%s:MAILTO:%s', $organizer_name, $organizer_email );
				} else {
					$item[] = sprintf( 'ORGANIZER:MAILTO:%s', $organizer_email );
				}
			}

			$item = apply_filters( 'tribe_ical_feed_item', $item, $event_post );

			$events .= "BEGIN:VEVENT\r\n" . implode( "\r\n", $item ) . "\r\nEND:VEVENT\r\n";
		}

		//header( 'Content-type: text/calendar; charset=UTF-8' );
		//header( 'Content-Disposition: attachment; filename="ical-event-' . implode( $event_ids ) . '.ics"' );
		$content = "BEGIN:VCALENDAR\r\n";
		$content .= "VERSION:2.0\r\n";
		$content .= 'PRODID:-//' . $blogName . ' - ECPv' . Tribe__Events__Main::VERSION . "//NONSGML v1.0//EN\r\n";
		$content .= "CALSCALE:GREGORIAN\r\n";
		$content .= "METHOD:PUBLISH\r\n";
		$content .= 'X-WR-CALNAME:' . apply_filters( 'tribe_ical_feed_calname', $blogName ) . "\r\n";
		$content .= 'X-ORIGINAL-URL:' . $blogHome . "\r\n";
		$content .= 'X-WR-CALDESC:Events for ' . $blogName . "\r\n";
		$content = apply_filters( 'tribe_ical_properties', $content );
		$content .= $events;
		$content .= 'END:VCALENDAR';
		return $content;
    }

    /**
	 * Converts a locally-formatted date to a unix timestamp. This is a drop-in
	 * replacement for `strtotime()`, except that where strtotime assumes GMT, this
	 * assumes local time (as described below). If a timezone is specified, this
	 * function defers to strtotime().
	 *
	 * If there is a timezone_string available, the date is assumed to be in that
	 * timezone, otherwise it simply subtracts the value of the 'gmt_offset'
	 * option.
	 *
	 * @see strtotime()
	 * @uses get_option() to retrieve the value of 'gmt_offset'.
	 * @param string $string A date/time string. See `strtotime` for valid formats.
	 * @return int UNIX timestamp.
	 */
	private static function wp_strtotime( $string ) {
		// If there's a timezone specified, we shouldn't convert it
		try {
			$test_date = new DateTime( $string );
			if ( 'UTC' != $test_date->getTimezone()->getName() ) {
				return strtotime( $string );
			}
		} catch ( Exception $e ) {
			return strtotime( $string );
		}

		$tz = get_option( 'timezone_string' );
		if ( ! empty( $tz ) ) {
			$date = date_create( $string, new DateTimeZone( $tz ) );
			if ( ! $date ) {
				return strtotime( $string );
			}
			$date->setTimezone( new DateTimeZone( 'UTC' ) );
			return $date->format( 'U' );
		} else {
			$offset = (float) get_option( 'gmt_offset' );
			$seconds = intval( $offset * HOUR_IN_SECONDS );
			$timestamp = strtotime( $string ) - $seconds;
			return $timestamp;
		}
	}

}
