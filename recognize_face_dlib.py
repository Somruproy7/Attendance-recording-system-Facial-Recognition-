import argparse
import base64
import json
import os
import sys
import tempfile
import numpy as np
import mysql.connector
import face_recognition


DB_CONFIG = {
	'host': 'localhost',
	'database': 'fullattend_db',
	'user': 'root',
	'password': ''
}


def get_connection():
    return mysql.connector.connect(**DB_CONFIG)


def ensure_encodings_from_profiles(conn):
    """If no encodings exist, build from uploads/profiles images and store in DB."""
    cur = conn.cursor()
    cur.execute("SELECT COUNT(*) FROM student_face_encodings")
    count = cur.fetchone()[0]
    if count and count > 0:
        cur.close()
        return

    profiles_dir = os.path.join(os.path.dirname(__file__), 'uploads', 'profiles')
    if not os.path.isdir(profiles_dir):
        cur.close()
        return

    insert_sql = (
        "INSERT INTO student_face_encodings (student_id, face_encoding, image_path) "
        "VALUES (%s, %s, %s)"
    )

    for fname in os.listdir(profiles_dir):
        if not fname.lower().endswith(('.png', '.jpg', '.jpeg')):
            continue
        # Try to extract numeric users.id from filename
        import re
        m = re.findall(r"\d+", fname)
        if not m:
            continue
        student_id = int(m[0])
        path = os.path.join(profiles_dir, fname)
        try:
            img = face_recognition.load_image_file(path)
            boxes = face_recognition.face_locations(img, model='hog')
            if not boxes:
                continue
            enc = face_recognition.face_encodings(img, boxes)[0]
            cur.execute(insert_sql, (student_id, json.dumps(enc.tolist()), os.path.join('uploads', 'profiles', fname)))
            conn.commit()
        except Exception:
            continue
    cur.close()


def load_known_encodings(conn):
    cur = conn.cursor()
    cur.execute(
        """
        SELECT sfe.student_id, sfe.face_encoding
        FROM student_face_encodings sfe
        JOIN users u ON u.id = sfe.student_id
        WHERE u.status = 'active'
        """
    )
    rows = cur.fetchall()
    cur.close()
    encodings = []
    student_ids = []
    for sid, enc_json in rows:
        try:
            enc = np.array(json.loads(enc_json))
            encodings.append(enc)
            student_ids.append(int(sid))
        except Exception:
            continue
    return encodings, student_ids


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--image_path', default=None)
    parser.add_argument('--image_base64', default=None)
    parser.add_argument('--threshold', type=float, default=0.6)
    parser.add_argument('--expected_student_id', type=int, default=None)
    args = parser.parse_args()

    if not args.image_path and not args.image_base64:
        print(json.dumps({'success': False, 'message': 'No image provided'}))
        return

    # Prepare image file
    image_path = args.image_path
    tmp_file = None
    if not image_path:
        data = args.image_base64
        if data.startswith('data:') and 'base64,' in data:
            data = data.split('base64,', 1)[1]
        raw = base64.b64decode(data)
        fd, tmp_file = tempfile.mkstemp(suffix='.png')
        with os.fdopen(fd, 'wb') as f:
            f.write(raw)
        image_path = tmp_file

    # Load query image
    img = face_recognition.load_image_file(image_path)
    boxes = face_recognition.face_locations(img, model='hog')
    if not boxes:
        print(json.dumps({'success': False, 'message': 'No face detected'}))
        if tmp_file:
            os.remove(tmp_file)
        return
    query_enc = face_recognition.face_encodings(img, boxes)[0]

    # Load known encodings (build from profiles if empty)
    conn = get_connection()
    ensure_encodings_from_profiles(conn)
    known_encodings, student_ids = load_known_encodings(conn)
    conn.close()

    if not known_encodings:
        print(json.dumps({'success': False, 'message': 'No registered faces'}))
        if tmp_file:
            os.remove(tmp_file)
        return

    distances = face_recognition.face_distance(known_encodings, query_enc)
    best_idx = int(np.argmin(distances))
    best_distance = float(distances[best_idx])
    confidence = 1.0 - best_distance  # 0..1 (approx)
    matched = confidence >= args.threshold
    student_id = int(student_ids[best_idx]) if matched else None

    if args.expected_student_id is not None:
        matched = matched and (student_id == int(args.expected_student_id))

    print(json.dumps({'success': True, 'matched': matched, 'student_id': student_id, 'confidence': confidence}))

    if tmp_file:
        os.remove(tmp_file)


if __name__ == '__main__':
    main()


