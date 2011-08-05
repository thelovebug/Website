<?php
/**
 * CCHits.net is a website designed to promote Creative Commons Music,
 * the artists who produce it and anyone or anywhere that plays it.
 * These files are used to generate the site.
 *
 * PHP version 5
 *
 * @category Default
 * @package  CCHitsClass
 * @author   Jon Spriggs <jon@sprig.gs>
 * @license  http://www.gnu.org/licenses/agpl.html AGPLv3
 * @link     http://cchits.net Actual web service
 * @link     http://code.cchits.net Developers Web Site
 * @link     http://gitorious.net/cchits-net Version Control Service
 */
/**
 * This class extends internal show object class to create weekly shows.
 *
 * @category Default
 * @package  Objects
 * @author   Jon Spriggs <jon@sprig.gs>
 * @license  http://www.gnu.org/licenses/agpl.html AGPLv3
 * @link     http://cchits.net Actual web service
 * @link     http://code.cchits.net Developers Web Site
 * @link     http://gitorious.net/cchits-net Version Control Service
 */
class NewWeeklyShowObject extends NewInternalShowObject
{
    /**
     * Establish the creation of the new item by setting the values and then calling the create function.
     *
     * @param integer $intShowUrl The date of the show in YYYYMMDD format
     *
     * @return boolean Creation status
     */
    public function __construct($intShowUrl = 0)
    {
        $datShowUrl = UI::makeLongDate($intShowUrl);
        $datLastWeekShowsUrl = date("Ymd", strtotime($datShowUrl . ' - 7 days'));
        $datOldShowsUrl = date("Ymd", strtotime($datShowUrl . ' -14 days'));
        $arrLastWeekShows = array();
        $arrOldShows = array();
        $arrLastWeekTracks = array();
        $arrOldTracks = array();
        for ($date = $datLastWeekShowsUrl; $date <= $intShowUrl; $date = date("Ymd", strtotime(UI::makeLongDate($date) . ' + 1 days'))) {
            $arrLastWeekShows[$date] = ShowBroker::getInternalShowByDate('daily', $date);
        }
        for ($date = $datOldShowsUrl; $date <= $datLastWeekShowsUrl ; $date = date("Ymd", strtotime(UI::makeLongDate($date) . ' + 1 days'))) {
            $arrOldShows[$date] = ShowBroker::getInternalShowByDate('daily', $date);
        }
        $status = parent::__construct($intShowUrl, 'weekly');
        if ($status) {
            foreach ($arrLastWeekShows as $show) {
                if ($show != false) {
                    $track = end($show->get_arrTracks());
                    $votes = VoteBroker::getVotesForTrackByShow($track->get_intTrackID());
                    $arrLastWeekTracks[$votes['adjusted']] = $track->get_intTrackID();
                }
            }
            ksort($arrLastWeekTracks);
            foreach ($arrOldShows as $show) {
                if ($show != false) {
                    $track = end($show->get_arrTracks());
                    $votes = VoteBroker::getVotesForTrackByShow($track->get_intTrackID());
                    $arrOldTracks[$votes['adjusted']] = $track->get_intTrackID();
                }
            }
            ksort($arrOldTracks);
            $arrOldTracks = array_slice($arrOldTracks, -3);
            foreach ($arrLastWeekTracks as $track) {
                $this->arrTracks[] = new NewShowTrackObject($track, $this->intShowID);
            }
            foreach ($arrOldTracks as $track) {
                $this->arrTracks[] = new NewShowTrackObject($track, $this->intShowID);
            }
        }
        return $this;
    }
}