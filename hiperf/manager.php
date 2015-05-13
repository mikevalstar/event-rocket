<?php
class EventRocket_HiPerfManager
{
	public function __construct() {

	}
}

/**
 * @return EventRocket_HiPerfManager
 */
function eventrocket_hiperf() {
	static $hiperf = null;
	if ( null === $hiperf ) $hiperf = new EventRocket_HiPerfManager;
	return $hiperf;
}