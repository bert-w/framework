<?php echo $__env->make('layout', get_defined_vars())->render(); ?>
<?php $__env->startSection('content'); ?>
<?php throw new Exception('section exception message') ?>
<?php $__env->stopSection(); ?>
