<?php
error_reporting(E_ALL | E_STRICT);

require_once('vendor/UploadHandler.php');
require_once('helper/server.php');
require_once('../database.php');

//for xmlrpc_encode_request
$host = "localhost";
$port = 8000;
$category_file = "../photo/category.txt";
$profile_path = "photo/userImages/";
$restaurant_path = "photo/restaurantImages/";

$options = array(
    'db_server' => $db_server,
    'db_user' => $db_user,
    'db_pass' => $db_pass,
    'db_name' => $db_name,
    'category' => '',
    // 'script_url' => get_full_url().'/videoupload', //for download and delete
    'mkdir_mode' => 0777,
    'upload_dir' => '../photo/',
    'upload_url' => get_full_url(__DIR__ . '/../') . 'photo/',
    'delete_type' => 'POST',
    'max_file_size' => 20971520,
    'param_name' => 'file_img',
    'image_versions' => array(
        // The empty image version key defines options for the original image:
        '' => array(
            // Automatically rotate images based on EXIF meta data:
            'auto_orient' => true
        ),
        // Uncomment the following to create medium sized images:
        /*
        'medium' => array(
            'max_width' => 800,
            'max_height' => 600
        ),
        */
        'thumbnail' => array(
            // Uncomment the following to use a defined directory for the thumbnails
            // instead of a subdirectory based on the version identifier.
            // Make sure that this directory doesn't allow execution of files if you
            // don't pose any restrictions on the type of uploaded files, e.g. by
            // copying the .htaccess file from the files directory for Apache:
            'upload_dir' => '../photo/thumb/',
            'upload_url' => get_full_url(__DIR__ . '/../') . 'photo/thumb/',
            // Uncomment the following to force the max
            // dimensions and e.g. create square thumbnails:
            //'crop' => true,
            'max_width' => 200,
            'max_height' => 150
        )
    ),
    'print_response' => false
);

Class myUploadHandler extends UploadHandler {
    protected function initialize() {

        //print_r($this->options);die;

         $this->db = new mysqli( $this->options['db_server'],  $this->options['db_user'],  $this->options['db_pass'],  $this->options['db_name']);
         //print_r($this->db);die;

        if ($this->db->connect_errno) {
            error_log("Error: Failed to make a MySQL connection, here is why: \n");
            error_log($this->db->connect_errno. "\n");
            error_log($this->db->connect_error . "\n");
            exit;
        }

        parent::initialize();

        $this->db->close();
    }

    protected function get_file_name($file_path, $name, $size, $type, $error,
            $index, $content_range) {

        $name = time();
        
        return $this->get_unique_filename(
            $file_path,
            $this->fix_file_extension($file_path, $name, $size, $type, $error,
                $index, $content_range),
            $size,
            $type,
            $error,
            $index,
            $content_range
        );

    }

    protected function upcount_name_callback($matches) {
        $index = isset($matches[1]) ? ((int)$matches[1]) + 1 : 1;
        $ext = isset($matches[2]) ? $matches[2] : '';
        return $index .$ext;
    }

    protected function upcount_name($name) {
        return preg_replace_callback(
            '/(?:(?: ([\d]+))?(\.[^.]+))?$/',
            array($this, 'upcount_name_callback'),
            $name,
            1
        );
    }

    protected function handle_file_upload($uploaded_file, $name, $size, $type, $error,
            $index = null, $content_range = null) {

        $file = parent::handle_file_upload(
                $uploaded_file, $name, $size, $type, $error, $index, $content_range
            );

        /*$sql = "INSERT INTO upload_img (img_name, img_desc, img_path, category, img_type) VALUES (?, ?, ?, ?, ?)"; 

        if(!isset($file->error))
        {
            $stmt = $this->db->prepare($sql);
            //print_r($file->name,$name,$file->url,$this->options['category'],$file->type);die;
            print_r($this->options['category']);die;
            $stmt->bind_param('sssss', $file->name, $name, $file->url, $this->options['category'], $file->type);
            $stmt->execute();

            if($stmt->affected_rows <= 0)
            {   
                $file->error = 'insert to DB Failed';
                error_log('insert to DB Failed');
            }
        }*/

        $file->description = $name;

        return $file;
    }

    function getOptions()
    {
        //print_r($this->options);die;
        return $this->options;
    }

}

$contentList['image']   = array();
$contentList['matches'] = array();
$contentList['user']    = array();
$contentList['restaurant'] = array();

$upload_handler = new myUploadHandler($options, true, null);

//process the image analysis
if($upload_handler->response != null)
{
    $options = $upload_handler->getOptions();

    //print_r($options);die;

    foreach ($upload_handler->response['file_img'] as $fileKey => $file)
    {

        if(!isset($file->error))
        {
            $categoryList = array();

            $contentList['image']['name'] = $file->description;
            $contentList['image']['thumbnailUrl'] = $file->thumbnailUrl;
            $contentList['image']['url'] = $file->url;

            $userid = rand(1,10);
            $restaurantid = 101;

            $root = $options['upload_dir'];
            $filepath = $root . $file->name;

            $request = xmlrpc_encode_request('handle', array($filepath, $userid, $restaurantid));
            $response = xmlrpc_decode(do_call($host, $port, $request));
            // print_r($response); die;
            if($response != null)
            {

                //declare at file head
                $handle = fopen( $category_file, "r");
                
                while (!feof($handle)) {
                    $line = fgets($handle);

                    if($line)
                        array_push($categoryList, $line);
                }

                fclose($file);

                //prepare tags output
                //=>top-k
                if($response[0] != null)
                {
 
                    foreach ($response[0] as $tagCount =>$tagId) {
                        if($tagId != '' && $categoryList != null)
                        {
                            $contentList['tags'][] = array(
                                'name' => $categoryList[$tagId],
                                'probabilities' =>  ($response[1] != null && isset($response[1][$tagCount])) ? $response[1][$tagCount] : '',
                            );
                        }
                    }
                }

                //imList
                if($response[2] != null)
                {
                    if($mysqli != null)
                    {
                        $sql = 'SELECT img_id, img_name, img_desc, img_path, category, author FROM food_img WHERE(';

                        foreach ($response[2] as $imageCount => $imageId) {
                            if($imageCount > 0)
                                $sql .= ' OR ';
                            $sql .= "img_id='$imageId'";
                        }

                        $sql .= ')';
                    

                        $result = $mysqli->query($sql);

                        while ($row = $result->fetch_assoc()) {
                            $rows[$row['img_id']] = $row;
                        }

                        if($rows != null)
                        {
                            foreach ($response[2] as $imageCount => $imageId) {
                                if(isset($rows[$imageId]))
                                {
                                    $contentList['matches'][] = array(
                                        // 'name' => $rows[$imageId]['img_name'],
                                        'desc' => $rows[$imageId]['img_desc'],
                                        'url'  => get_full_url(__DIR__ . '/../') . $rows[$imageId]['img_path'],
                                        'percentage' => ($response[3] != null && isset($response[3][$imageCount])) ? $response[3][$imageCount] : '',
                                        'category' => isset($categoryList) ? $categoryList[ $rows[$imageId]['category'] ] : '',
                                        'author' => $rows[$imageId]['author'],
                                    );

                                }
                            }
                        }

                    } else {
                        die('no database was selected');
                    } //end mysqli
                }// end response[2]  

                //userlist
                if($response[4] != null)
                {
                    foreach ($response[4] as $user) {
                        $contentList['user'][] = array(
                            'profile_img' => get_full_url(__DIR__ . '/../') . $profile_path . $user . '.jpg'
                        );
                    }
                }

                //restaurantlist
                if($response[5] != null)
                {
                    foreach ($response[5] as $restaurant) {
                        $contentList['restaurant'][] = array(
                            'restaurant_img' => get_full_url(__DIR__ . '/../') . $restaurant_path . $restaurant . '.jpg'
                        );
                    }
                }

                $contentList['code'] = '0';
                $contentList['errMsg'] = '';

            }//end response 
        } else {
                $contentList['code'] = '998';
                $contentList['errMsg'] = $file->error;
        }
    }// end for

} else {
    $contentList['code'] = '999';
    $contentList['errMsg'] = 'upload failed'; 
}

echo json_encode($contentList);


?>