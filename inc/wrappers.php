<?php


if(!function_exists('get_the_dates')){
    /**
     *
     * @global \EventPost $EventPost
     * @param mixte $post
     * @return string
     */
    function get_the_dates($post=null){
        global $EventPost;
        return $EventPost->print_date($post);
    }
}
if(!function_exists('the_dates')){
    /**
     *
     * @param mixte $post
     */
    function the_dates($post=null){
        echo get_the_dates($post);
    }
}

if(!function_exists('get_the_date_start')){
    /**
     *
     * @global \EventPost $EventPost
     * @param type $post
     * @return string
     */
    function get_the_date_start($post=null){
        global $EventPost;
        $event = $EventPost->retreive($post);
        return $EventPost->human_date($event->time_start, $EventPost->settings['dateformat']);
    }
}
if(!function_exists('the_date_start')){
    /**
     *
     * @param type $post
     */
    function the_date_start($post=null){
        echo get_the_date_start($post);
    }
}

if(!function_exists('get_the_date_end')){
    /**
     *
     * @global \EventPost $EventPost
     * @param type $post
     * @return string
     */
    function get_the_date_end($post=null){
        global $EventPost;
        $event = $EventPost->retreive($post);
        return $EventPost->human_date($event->time_end, $EventPost->settings['dateformat']);
    }
}
if(!function_exists('the_date_end')){
    /**
     *
     */
    function the_date_end($post=null){
        echo get_the_date_end($post);
    }
}

if(!function_exists('get_the_location')){
    /**
     *
     * @global \EventPost $EventPost
     * @param type $post
     * @return string
     */
    function get_the_location($post=null){
        global $EventPost;
        return $EventPost->print_location($post);
    }
}
if(!function_exists('the_location')){
    /**
     *
     * @param type $post
     */
    function the_location($post=null){
        echo get_the_location($post);
    }
}