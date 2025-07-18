<?php
require('../../config.php');

require_login(); // o rimuovi se vuoi accesso pubblico

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/myaapp/index.php'));
$PAGE->set_title('La mia app full width');
$PAGE->set_heading('Configuratore Corsi');
$PAGE->set_pagelayout('standard');


echo $OUTPUT->header();

?>
<iframe id="myAppFrame" src="index.html" style="width:100%; border:none; min-height:900px" ></iframe>

<?php



echo $OUTPUT->footer();