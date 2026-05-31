        </main>
    </div>
</div>

<script src="<?= BASE_URL ?>/assets/js/api.js"></script>
<script src="<?= BASE_URL ?>/assets/js/notifications.js"></script>
<script src="<?= BASE_URL ?>/assets/js/dropdown.js"></script>
<?php if(isset($extraJs)): ?>
    <script src="<?= BASE_URL ?>/assets/js/<?= $extraJs ?>"></script>
<?php endif; ?>
</body>
</html>