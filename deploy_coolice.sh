#!/bin/bash
set -euo pipefail

# ==============================================================================
# SCRIPT FOR DEPLOYING THE COOLICE GITHUB REPOSITORY
# ==============================================================================
#
# Tutorial Name: The deployment of each and every file from the
#                `https://github.com/ib-bsb-br/coolice` github repository into
#                the `coolicehost` nginx standalone shared server via
#                directadmin panel dashboard terminal application that will run
#                the bash script from the `/home/ibbsbbry` user home directory.
#
# Target System: Debian 11 (Bullseye) on ARM64 RK3588
#
# Description:   This script automates the full deployment of the 'coolice'
#                web application from its GitHub repository. It ensures the
#                necessary tools are installed, clones the repository, and
#                safely synchronizes the files to the web server's document
#                root, replacing any existing content.
#
# Execution:     This script is designed to be executed by the 'ibbsbbry' user
#                directly from their home directory: /home/ibbsbbry
#
# ==============================================================================

# --- Configuration Variables ---

# GitHub repository to be deployed.
readonly REPO_URL="https://github.com/ib-bsb-br/coolice.git"

# The user account under which the script is expected to run.
readonly TARGET_USER="ibbsbbry"

# The home directory of the target user.
readonly USER_HOME="/home/${TARGET_USER}"

# !!! IMPORTANT !!!
# Destination directory for the web files. This is the public document root for the domain.
# This path is an assumption based on standard DirectAdmin setups for the user 'ibbsbbry'
# and domain 'coolicehost'.
# PLEASE VERIFY THIS PATH IS CORRECT FOR YOUR 'coolicehost' DOMAIN BEFORE RUNNING.
readonly DEST_DIR="${USER_HOME}/domains/coolicehost/public_html"

# Temporary directory for cloning the repository. Will be created securely.
TEMP_CLONE_DIR=""


# --- Helper Functions ---

# Function to be called on script exit to clean up temporary files.
# This ensures that we don't leave cloned repository data in a temp folder.
cleanup_temp_files() {
    echo "---"
    echo "Executing cleanup..."
    if [ -n "$TEMP_CLONE_DIR" ] && [ -d "$TEMP_CLONE_DIR" ]; then
        echo "Removing temporary clone directory: $TEMP_CLONE_DIR"
        rm -rf "$TEMP_CLONE_DIR"
        echo "Temporary directory removed."
    else
        echo "No temporary directory to clean up."
    fi
    echo "Cleanup complete."
}
# Register the cleanup function to run on script EXIT (successful or not),
# or if it's interrupted (SIGINT) or terminated (SIGTERM).
trap cleanup_temp_files EXIT SIGINT SIGTERM

# Function to ensure a command-line tool is installed via APT.
# It checks if the tool exists first, making the script idempotent.
ensure_tool_installed() {
    local tool_name="$1"
    local package_name="${2:-$tool_name}" # Use second arg as package name if provided, else tool_name
    echo "Checking for required tool: '$tool_name'..."
    if ! command -v "$tool_name" >/dev/null 2>&1; then
        echo "Tool '$tool_name' not found. Attempting to install package '$package_name'..."
        # Using sudo for installation. The user will be prompted for their password if not cached.
        if ! sudo apt-get update -y; then
            printf "ERROR: 'apt-get update' failed. Please check your network connection and permissions.\n" >&2
            exit 1
        fi
        if ! sudo apt-get install -y "$package_name"; then
            printf "ERROR: Failed to install package '%s'. Please install it manually and re-run the script.\n" "$package_name" >&2
            exit 1
        fi
        echo "Package '$package_name' installed successfully."
    else
        echo "Tool '$tool_name' is already installed."
    fi
}


# --- Main Script Logic ---

echo "========================================================"
echo "Starting Deployment Script for the 'coolice' Repository"
echo "========================================================"
echo

# --- Pre-flight Checks ---
echo "---"
echo "Performing pre-flight checks..."

# Check 1: Verify the script is being run by the correct user from the correct directory.
if [ "$USER" != "$TARGET_USER" ]; then
    echo "ERROR: This script must be run by the '$TARGET_USER' user." >&2
    echo "You are currently user '$USER'." >&2
    echo "Please log in as '$TARGET_USER' before executing this script." >&2
    exit 1
fi
if [ "$PWD" != "$USER_HOME" ]; then
    echo "ERROR: This script must be run from within the '$USER_HOME' directory." >&2
    echo "You are currently in directory '$PWD'." >&2
    echo "Please run 'cd ~' before executing this script." >&2
    exit 1
fi
echo "✓ Script running as correct user and from the correct directory."

# Check 2: Ensure necessary tools (git, rsync) are installed.
ensure_tool_installed "git"
ensure_tool_installed "rsync"

# Check 3: Verify the assumed destination directory exists.
if [ ! -d "$DEST_DIR" ]; then
    echo "ERROR: The destination web directory '$DEST_DIR' does not exist." >&2
    echo "This script assumes the domain 'coolicehost' is set up in DirectAdmin." >&2
    echo "Please create the domain via the panel, which should create the directory, then re-run." >&2
    exit 1
fi
echo "✓ Destination directory '$DEST_DIR' found."
echo "All pre-flight checks passed."
echo

# --- Critical Safety Confirmation ---
echo "---"
echo "!!!!!!!!!!!!!!!!!!!!!!!!!!!!"
echo "!!! CRITICAL ACTION WARNING !!!"
echo "!!!!!!!!!!!!!!!!!!!!!!!!!!!!"
echo
echo "The next step will synchronize the contents of the repository with your live web directory:"
echo "  => $DEST_DIR"
echo
echo "This is a DESTRUCTIVE operation:"
echo "  1. ALL files and folders currently in the destination directory that are NOT in the"
echo "     repository WILL BE PERMANENTLY DELETED."
echo "  2. Any files in the destination that are also in the repository WILL BE OVERWRITTEN."
echo
echo "This action cannot be undone. Please ensure you have backups if necessary."
echo
read -r -p "To confirm you understand and wish to proceed, please type exactly 'yes': " confirmation
if [[ "$confirmation" != "yes" ]]; then
    echo "Operation cancelled by the user. Your files have not been changed." >&2
    exit 1
fi
echo "Confirmation received. Proceeding with deployment..."
echo

# --- Step 1: Clone Repository ---
echo "---"
echo "Cloning repository from '$REPO_URL' into a temporary directory..."
# Create a secure, temporary directory for the clone.
# The path is stored in TEMP_CLONE_DIR, which will be cleaned up automatically on exit.
TEMP_CLONE_DIR=$(mktemp -d)
chmod 700 "$TEMP_CLONE_DIR"
if ! git clone --depth 1 "$REPO_URL" "$TEMP_CLONE_DIR"; then
    echo "ERROR: Failed to clone repository from '$REPO_URL'." >&2
    # The trap will still fire to clean up the partially created temp directory.
    exit 1
fi
echo "✓ Repository cloned successfully."
echo

# --- Step 2: Deploy Files using rsync ---
echo "---"
echo "Synchronizing files to '$DEST_DIR'..."
# Use rsync to efficiently and safely copy the files.
#  -a: archive mode (preserves permissions, symbolic links, etc.)
#  -v: verbose (shows which files are being transferred)
#  --delete: this is the key flag that deletes files in DEST_DIR that are not in the source.
#  --exclude='.git*': prevents the .git directory and .gitignore/.gitattributes from being copied.
# The trailing slash on the source directory is crucial - it means "copy the contents of".
if ! rsync -av --delete --exclude='.git*' "${TEMP_CLONE_DIR}/" "${DEST_DIR}/"; then
    echo "ERROR: Failed to synchronize files to the destination directory." >&2
    exit 1
fi
echo "✓ File synchronization complete."
echo

# --- Finalization ---
echo "---"
echo "========================================================"
echo "SUCCESS: Deployment of the 'coolice' repository is complete."
echo "Your website at 'coolicehost' should now be updated."
echo "========================================================"
echo

# The cleanup function will run automatically upon exit.
exit 0
