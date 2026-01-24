</div>
</main>
</div>

<!-- Magic FAB -->
<div class="dropdown position-fixed bottom-0 end-0 m-4" style="z-index: 1050;">
    <button class="btn btn-primary rounded-circle shadow-lg p-3 d-flex align-items-center justify-content-center"
        type="button" data-bs-toggle="dropdown" aria-expanded="false" style="width: 60px; height: 60px;">
        <i class="fa-solid fa-plus fa-xl"></i>
    </button>
    <ul class="dropdown-menu dropdown-menu-end shadow-lg border-0 mb-2 rounded-4 p-2">
        <li><a class="dropdown-item rounded-3 py-2 fw-bold text-danger" href="add_expense.php"><i
                    class="fa-solid fa-receipt me-2"></i> Add Expense</a></li>
        <li><a class="dropdown-item rounded-3 py-2 fw-bold text-success" href="add_income.php"><i
                    class="fa-solid fa-wallet me-2"></i> Add Income</a></li>
        <li>
            <hr class="dropdown-divider">
        </li>
        <li><a class="dropdown-item rounded-3 py-2 small" href="add_card.php"><i
                    class="fa-solid fa-credit-card me-2"></i> Add Card</a></li>
    </ul>
</div>

<!-- Command Bar Modal -->
<div class="modal fade" id="commandModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content glass-panel border-0" style="background: rgba(255, 255, 255, 0.95);">
            <div class="modal-body p-0">
                <div class="input-group">
                    <span class="input-group-text border-0 bg-transparent ps-4"><i
                            class="fa-solid fa-terminal text-muted"></i></span>
                    <input type="text" id="cmdInput"
                        class="form-control form-control-lg border-0 bg-transparent py-4 shadow-none"
                        placeholder="Type a command... (e.g. 'add expense 50 coffee', 'show cards')" autocomplete="off">
                </div>
                <div
                    class="border-top px-4 py-2 bg-light rounded-bottom-4 d-flex justify-content-between text-muted small">
                    <span><span class="badge bg-secondary">ENTER</span> to run</span>
                    <span><span class="badge bg-secondary">ESC</span> to close</span>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Global Info Modal -->
<div class="modal fade" id="globalInfoModal" tabindex="-1" aria-hidden="true" style="z-index: 1060;">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content glass-panel border-0">
            <div class="modal-header border-0">
                <h5 class="modal-title fw-bold" id="globalInfoModalTitle">Notification</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center py-4" id="globalInfoModalBody">
                <!-- Message -->
            </div>
            <div class="modal-footer border-0 justify-content-center">
                <button type="button" class="btn btn-primary px-4 fw-bold" data-bs-dismiss="modal">Okay, Got it</button>
            </div>
        </div>
    </div>
</div>

<!-- Global Confirm Modal -->
<div class="modal fade" id="globalConfirmModal" tabindex="-1" aria-hidden="true" style="z-index: 1060;">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content glass-panel border-0">
            <div class="modal-header border-0 bg-danger bg-opacity-10">
                <h5 class="modal-title fw-bold text-danger" id="globalConfirmModalTitle">
                    <i class="fa-solid fa-triangle-exclamation me-2"></i>Confirm Action
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center py-4" id="globalConfirmModalBody">
                Are you sure?
            </div>
            <div class="modal-footer border-0 justify-content-center gap-2">
                <button type="button" class="btn btn-light px-4" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger px-4 fw-bold" id="globalConfirmBtn">
                    <i class="fa-solid fa-trash me-1"></i> Delete
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Command Bar Modal -->
<div class="modal fade" id="commandModal" tabindex="-1" aria-hidden="true">

    <script>
        document.addEventListener('keydown', function (e) {
            if ((e.metaKey || e.ctrlKey) && e.key === 'k') {
                e.preventDefault();
                const modal = new bootstrap.Modal(document.getElementById('commandModal'));
                modal.show();
                document.getElementById('cmdInput').focus();
            }
        });

        document.getElementById('commandModal').addEventListener('shown.bs.modal', function () {
            document.getElementById('cmdInput').focus();
        });

        document.getElementById('cmdInput').addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                const input = this.value.trim();
                if (!input) return;
                processCommand(input);
            }
        });

        function processCommand(cmd) {
            const parts = cmd.split(' ');
            const action = parts[0].toLowerCase();

            if (action === 'add' && parts.length >= 4) {
                const type = parts[1].toLowerCase(); // expense or income
                const amount = parts[2];
                const desc = parts.slice(3).join(' ');

                if (type === 'expense') {
                    window.location.href = `add_expense.php?amount=${amount}&description=${encodeURIComponent(desc)}`;
                } else if (type === 'income') {
                    window.location.href = `add_income.php?amount=${amount}&description=${encodeURIComponent(desc)}`;
                }
            }
            else if (action === 'show' || action === 'goto') {
                const page = parts[1].toLowerCase();
                const map = {
                    'dashboard': 'dashboard.php',
                    'cards': 'my_cards.php',
                    'wallet': 'my_cards.php',
                    'users': 'my_cards.php', // alias
                    'expenses': 'monthly_expenses.php',
                    'income': 'monthly_income.php',
                    'budget': 'budget.php',
                    'subs': 'subscriptions.php',
                    'subscriptions': 'subscriptions.php'
                };
                if (map[page]) window.location.href = map[page];
            }
            else if (action === 'refresh') {
                location.reload();
            }
        }

        // Global Modal Helper
        function showGlobalModal(message, title = "Notification") {
            document.getElementById('globalInfoModalBody').innerHTML = message;
            document.getElementById('globalInfoModalTitle').innerText = title;
            new bootstrap.Modal(document.getElementById('globalInfoModal')).show();
        }

        // Global Confirm Helper (for delete actions)
        function confirmDelete(url, message = "Are you sure you want to delete this?", buttonText = "Delete") {
            document.getElementById('globalConfirmModalBody').innerHTML = message;
            document.getElementById('globalConfirmBtn').innerHTML = `<i class="fa-solid fa-trash me-1"></i> ${buttonText}`;
            const modal = new bootstrap.Modal(document.getElementById('globalConfirmModal'));

            // Set up the confirm button action
            document.getElementById('globalConfirmBtn').onclick = function () {
                modal.hide();
                window.location.href = url;
            };

            modal.show();
            return false; // Prevent default link action
        }

        // For forms - submit after confirmation
        function confirmSubmit(formElement, message = "Are you sure?") {
            document.getElementById('globalConfirmModalBody').innerHTML = message;
            const modal = new bootstrap.Modal(document.getElementById('globalConfirmModal'));

            document.getElementById('globalConfirmBtn').onclick = function () {
                modal.hide();
                formElement.submit();
            };

            modal.show();
            return false;
        }
    </script>

    <!-- Service Worker Registration -->
    <script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => {
                navigator.serviceWorker.register('sw.js')
                    .then(reg => console.log('SW Registered!', reg.scope))
                    .catch(err => console.log('SW Failed:', err));
            });
        }
    </script>

    <!-- Bootstrap JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    </body>

    </html>